<?php
/**
 * SCM iFirma integration module for PrestaShop.
 *
 * @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
 * @copyright 2026 SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 * SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
 * See the LICENCE file distributed with this module for the full terms.
 */

/**
 * Shared in-memory registry for entity stubs. Every using class gets its own
 * static $instances (id => object). Tests seed it with register().
 */
trait IfirmaTestRegistryTrait
{
    /** @var array id => object */
    public static $instances = [];

    public static function register($object)
    {
        static::$instances[$object->id] = $object;

        return $object;
    }

    public static function reset()
    {
        static::$instances = [];
    }

    protected function loadFromRegistry($id)
    {
        if ($id && isset(static::$instances[$id])) {
            foreach (get_object_vars(static::$instances[$id]) as $k => $v) {
                $this->$k = $v;
            }
        }
    }
}
