<?php

declare(strict_types=1);

namespace johnfmorton\llmready\helpers;

use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * HTTP error exceptions that render with the right status on Craft 5 and 6.
 *
 * On Craft 6 the yii2 adapter turns a Yii HttpException into its status code
 * only on some routes. Thrown from an action reached through a site URL rule
 * (`/llms.txt`, `*.md`), it escapes as an unknown exception and renders as a
 * 500. Symfony's HTTP exceptions, which Laravel renders natively, don't have
 * that problem. Craft 5 doesn't ship Symfony's HTTP kernel, so those classes
 * are named as strings and only used on Craft 6.
 */
final class HttpErrors
{
    public static function notFound(string $message = ''): Throwable
    {
        return self::isCraft6()
            ? self::symfony('NotFoundHttpException', $message)
            : new NotFoundHttpException($message);
    }

    public static function forbidden(string $message = ''): Throwable
    {
        return self::isCraft6()
            ? self::symfony('AccessDeniedHttpException', $message)
            : new ForbiddenHttpException($message);
    }

    private static function isCraft6(): bool
    {
        return class_exists('CraftCms\\Cms\\Cms');
    }

    private static function symfony(string $class, string $message): Throwable
    {
        $fqcn = 'Symfony\\Component\\HttpKernel\\Exception\\' . $class;
        /** @var Throwable $exception */
        $exception = new $fqcn($message);
        return $exception;
    }
}
