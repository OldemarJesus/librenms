<?php

/**
 * DuskUnsafeException.php
 *
 * Dusk is installed and the application is in production
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2019 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace LibreNMS\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LibreNMS\Interfaces\Exceptions\UpgradeableException;
use Throwable;

class DuskUnsafeException extends \Exception implements UpgradeableException
{
    /**
     * Try to convert the given Exception to this exception
     */
    public static function upgrade(Throwable $exception): ?static
    {
        return $exception->getMessage() == 'It is unsafe to run Dusk in production.' ?
            new static($exception->getMessage(), $exception->getCode(), $exception) :
            null;
    }

    /**
     * Render the exception into an HTTP or JSON response.
     */
    public function render(Request $request): Response|JsonResponse
    {
        $title = trans('exceptions.dusk_unsafe.title');
        $message = trans('exceptions.dusk_unsafe.message', ['command' => './scripts/composer_wrapper.php install --no-dev']);

        return $request->wantsJson() ? response()->json([
            'status' => 'error',
            'message' => "$title: $message",
        ], 500) : response()->view('errors.generic', [
            'title' => $title,
            'content' => $message,
        ], 500);
    }
}
