<?php

namespace LibreNMS\Data\Store;

use App\Facades\DeviceCache;
use App\Polling\Measure\Measurement;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use LibreNMS\Config;
use RdKafka\Conf;
use RdKafka\FFI\Library;
use RdKafka\Message;
use RdKafka\Producer;

class Kafka extends BaseDatastore
{
    private $client = null;
    private $device_id = 0;
    private $kafkaFlushTimeout = 100;

    public function __construct(Producer $client)
    {
        parent::__construct();

        $this->client = $client;

        // Cache the flush timeout value early to avoid Config during shutdown
        if ($this->kafkaFlushTimeout == null) {
            $this->kafkaFlushTimeout = Config::get('kafka.flush.timeout', 100);
        }
    }

    public function __destruct()
    {
        $this->terminate();
    }

    public function terminate(): void
    {
        // Safely flush the producer to ensure all messages are sent before shutdown
        $this->safeFlush();
    }

    public static function getClient(): Producer
    {
        $conf = new Conf();
        // Set the log level
        $conf->set('log_level', (string) LOG_DEBUG);
        // Set the log callback for exceptions
        $conf->setDrMsgCb(
            function (Producer $producer, Message $message): void {
                if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                    error_log($message->errstr());
                }
            }
        );
        // Set the log callback for logs
        $conf->setLogCb(
            function (Producer $producer, int $level, string $facility, string $message): void {
                error_log('KAFKA: ' . $message);
            }
        );

        // Set the kafka broker servers
        $conf->set('bootstrap.servers', Config::get('kafka.broker.list', '127.0.2.2:9092'));
        // Set the idempotence
        $conf->set('enable.idempotence', Config::get('kafka.idempotence', false) ? 'true' : 'false');
        // Max queue allowed messages in poller memory
        $conf->set('queue.buffering.max.messages', Config::get('kafka.buffer.max.message', 1_000));
        // Num of messages each call to kafka
        $conf->set('batch.num.messages', Config::get('kafka.batch.max.message', 200));
        // Max wait time to acumulate before sending the batch
        $conf->set('linger.ms', Config::get('kafka.linger.ms', default: 50));
        // Change ACK
        $conf->set(
            'request.required.acks',
            // If idempotence is enabled, set to 'all' to ensure all messages are acknowledged
            // Otherwise, use the configured value or default to '1'
            // '1' means the leader will acknowledge the message, 'all' means all replicas must acknowledge
            Config::get('kafka.idempotence', false) ? 'all' :
                (Config::get('kafka.request.required.acks', '-1'))
        );

        // check if debug for ssl was set and enable it
        $confKafkaSSLDebug = Config::get('kafka.security.debug', null);
        $confKafkaSSLDebug != null || strlen($confKafkaSSLDebug) !== 0 ? $conf->set('debug', $confKafkaSSLDebug) : null;

        // config ssl
        $isSslEnabled = Config::get('kafka.ssl.enable', false);
        if ($isSslEnabled) {
            $conf->set('security.protocol', Config::get('kafka.ssl.protocol', 'ssl'));
            $conf->set('ssl.endpoint.identification.algorithm', 'none');

            // prepare all necessary librenms kafka config with associated rdkafka key
            $kafkaSSLConfigs = [
                'kafka.ssl.keystore.location' => 'ssl.keystore.location',
                'kafka.ssl.keystore.password' => 'ssl.keystore.password',
                'kafka.ssl.ca.location' => 'ssl.ca.location',
                'kafka.ssl.certificate.location' => 'ssl.certificate.location',
                'kafka.ssl.key.location' => 'ssl.key.location',
                'kafka.ssl.key.password' => 'ssl.key.password',
            ];

            // fetch kafka config values, if exists, associate its value to rdkafka key
            foreach ($kafkaSSLConfigs as $configKey => $kafkaKey) {
                $configValue = Config::get($configKey, null);
                $configValue != null || strlen($configValue) !== 0 ? $conf->set($kafkaKey, $configValue) : null;
            }
        }

        return new Producer($conf);
    }

    public function safeFlush()
    {
        // check if client instance exists
        if ($this->client === null) {
            return;
        }

        try {
            // get total number of messages in the queue
            $outQLen = $this->client->getOutQLen();

            if ($outQLen > 0) {
                // During shutdown, Log facades might not work properly, use d_echo as fallback
                error_log("KAFKA: SafeFlush | Flushing {$outQLen} remaining messages");

                // Use cached timeout value to avoid Config during shutdown
                $result = $this->client->flush($this->kafkaFlushTimeout);

                if (RD_KAFKA_RESP_ERR_NO_ERROR !== $result) {
                    $error_msg = sprintf(
                        'KAFKA: SafeFlush | Flush failed. Error: %s, Code: %d, Device ID: %d, Remaining: %d',
                        Library::rd_kafka_err2str($result),
                        $result,
                        $this->device_id,
                        $this->client->getOutQLen()
                    );

                    error_log($error_msg);
                }
            }
        } catch (\Throwable $e) {
            $error_msg = 'KAFKA: SafeFlush | failed with exception. Error: ' . $e->getMessage() . '. Trace: ' . $e->getTraceAsString();
            error_log($error_msg);
        } finally {
            // Reset the client to null to avoid further operations
            $this->client = null;
        }
    }

    public function getName(): string
    {
        return 'Kafka';
    }

    public static function isEnabled(): bool
    {
        return Config::get('kafka.enable', false);
    }

    public function getKafkaFlushTimeout()
    {
        return $this->kafkaFlushTimeout;
    }

    /**
     * @inheritDoc
     */
    public function write(string $measurement, array $fields, array $tags = [], array $meta = []): void
    {
        try {
            $device = $this->getDevice($meta);
            // get the singleton instance of the produced
            /** @var Producer $producer */
            $producer = $this->client;
            $this->device_id = $device['device_id'];
            $topic = $producer->newTopic(Kafka::getTopicName());

            $device_data = DeviceCache::get($device['device_id']);
            $excluded_groups = Config::get('kafka.groups-exclude'); // comman separated string
            $excluded_measurement = Config::get('kafka.measurement-exclude'); // comman separated string
            $excluded_device_fields = Config::get('kafka.device-fields-exclude'); // comman separated string
            $excluded_device_fields_arr = [];

            if ($excluded_groups != null && strlen($excluded_groups) > 0) {
                // convert into array
                $excluded_groups_arr = explode(',', strtoupper($excluded_groups));

                $device_groups = $device_data->groups;
                foreach ($device_groups as $group) {
                    // The group name will always be parsed as lowercase, even when uppercase in the GUI.
                    if (in_array(strtoupper($group->name), $excluded_groups_arr)) {
                        Log::debug('KAFKA: Skipped parsing to Kafka, device is in group: ' . $group->name);

                        return;
                    }
                }
            }

            if ($excluded_measurement != null && strlen($excluded_measurement) > 0) {
                // convert into array
                $excluded_measurement_arr = explode(',', $excluded_measurement);

                if (in_array($measurement, $excluded_measurement_arr)) {
                    Log::debug('KAFKA: Skipped parsing to Kafka, measurement is in measurement-excluded: ' . $measurement);

                    return;
                }
            }

            if ($excluded_device_fields != null && strlen($excluded_device_fields) > 0) {
                // convert into array
                $excluded_device_fields_arr = explode(',', $excluded_device_fields);
            }

            // start
            $stat = Measurement::start('write');

            $tmp_fields = [];
            $tmp_tags = [];
            $tmp_tags['device_groups'] = implode('|', $device_data->groups->pluck('name')->toArray());

            foreach ($tags as $k => $v) {
                if (empty($v)) {
                    $v = '_blank_';
                }
                $tmp_tags[$k] = $v;
            }
            foreach ($fields as $k => $v) {
                if ($k == 'time') {
                    $k = 'rtime';
                }

                if (($value = $this->forceType($v)) !== null) {
                    $tmp_fields[$k] = $value;
                }
            }

            if (empty($tmp_fields)) {
                Log::warning('KAFKA: All fields empty, skipping update', [
                    'orig_fields' => $fields,
                    'device_id' => $this->device_id,
                ]);

                return;
            }

            // create and organize data
            $filteredDeviceData = array_diff_key($device->toArray(), array_flip($excluded_device_fields_arr));
            // add current time to the data
            $filteredDeviceData['current_polled_time'] = Carbon::now();

            $resultArr = [
                'measurement' => $measurement,
                'device' => $filteredDeviceData,
                'fields' => $tmp_fields,
                'tags' => $tmp_tags,
            ];

            if (Config::get('kafka.debug') === true) {
                Log::debug('Kafka data: ', [
                    'device_id' => $this->device_id,
                    'measurement' => $measurement,
                    'fields' => $tmp_fields,
                ]);
            }

            $dataArr = json_encode($resultArr);
            $topic->produce(RD_KAFKA_PARTITION_UA, 0, $dataArr);

            // If debug is enabled, log the total size of the data being sent
            if (Config::get('kafka.debug') === true) {
                $outQLen = $this->client->getOutQLen();
                Log::debug('KAFKA: Flush | Data size', [
                    'device_id' => $this->device_id,
                    'measurement' => $measurement,
                    'size' => $outQLen,
                ]);
            }

            $producer->poll(0);

            // end
            $this->recordStatistic($stat->end());
        } catch (\Throwable $e) {
            Log::error('KAFKA: Put failed with exception', [
                'device_id' => $this->device_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function forceType($data)
    {
        /*
         * It is not trivial to detect if something is a float or an integer, and
         * therefore may cause breakages on inserts.
         * Just setting every number to a float gets around this, but may introduce
         * inefficiencies.
         */

        if (is_numeric($data)) {
            return floatval($data);
        }

        return $data === 'U' ? null : $data;
    }

    public static function getTopicName()
    {
        return Config::get('kafka.topic', 'librenms');
    }

    /**
     * Checks if the datastore wants rrdtags to be sent when issuing put()
     *
     * @return bool
     */
    public function wantsRrdTags()
    {
        return false;
    }
}
