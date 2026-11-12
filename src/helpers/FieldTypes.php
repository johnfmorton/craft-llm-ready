<?php

declare(strict_types=1);

namespace johnfmorton\llmready\helpers;

use craft\fields\Url;

/**
 * Field type checks that work on both Craft 5 and Craft 6.
 *
 * Copied from Twig Scaffold 1.4.1 (src/helpers/FieldTypes.php); keep the
 * copies in sync. See craft6plugindev/docs/porting-plugins.md.
 *
 * On Craft 6, fields are `CraftCms\Cms\Field\*` objects. The yii2 adapter's
 * `craft\fields\*` classes extend them rather than alias them, so a field the
 * control panel created fails `instanceof craft\fields\PlainText`, and none of
 * them implement `craft\base\FieldInterface`. These helpers check against the
 * Craft 6 class when it exists (it is the parent, so it matches legacy
 * subclasses too) and against the Craft 5 class otherwise.
 */
final class FieldTypes
{
    private const NATIVE_NAMESPACE = 'CraftCms\\Cms\\Field\\';
    private const NATIVE_FIELD_INTERFACE = 'CraftCms\\Cms\\Field\\Contracts\\FieldInterface';
    private const LEGACY_NAMESPACE = 'craft\\fields\\';

    /** @var array<string, string|false> */
    private static array $resolved = [];

    /**
     * Whether the value is a field of the given Craft 5 field class (or one
     * of its subclasses), whichever Craft version is running.
     *
     * @param class-string $class a `craft\fields\*` class
     */
    public static function is(mixed $field, string $class): bool
    {
        if (!is_object($field)) {
            return false;
        }

        $target = self::$resolved[$class] ??= self::resolve($class);

        return $target !== false && $field instanceof $target;
    }

    /** Whether the value is a custom field on either Craft version. */
    public static function isField(mixed $value): bool
    {
        return is_object($value) && (
            $value instanceof \craft\base\FieldInterface
            || (interface_exists(self::NATIVE_FIELD_INTERFACE) && is_a($value, self::NATIVE_FIELD_INTERFACE))
        );
    }

    /** Whether Craft 6 field classes are present. */
    public static function isCraft6(): bool
    {
        return interface_exists(self::NATIVE_FIELD_INTERFACE);
    }

    /**
     * The class to test against, or false when the type doesn't exist as its
     * own thing on this Craft version.
     */
    private static function resolve(string $class): string|false
    {
        if (!self::isCraft6() || !str_starts_with($class, self::LEGACY_NAMESPACE)) {
            return $class;
        }

        // Craft 6 merged URL fields into Link; the adapter aliases
        // craft\fields\Url to Link, which would claim every Link field.
        if ($class === Url::class) {
            return false;
        }

        $native = self::NATIVE_NAMESPACE . substr($class, strlen(self::LEGACY_NAMESPACE));

        // Types with no Craft 6 counterpart (Categories, Tags) only exist as
        // adapter classes, so the legacy class is the right test.
        return class_exists($native) ? $native : $class;
    }
}
