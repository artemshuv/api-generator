<?php

namespace SoliDry\Helpers;

use SoliDry\Types\PhpInterface;

/**
 * Class MigrationsHelper
 * @package SoliDry\Helpers
 */
class MigrationsHelper
{
    private const PATTERN_SPLIT_UC = '/(?=[A-Z])/';

    /**
     * @param string $objectName
     * @return string
     */
    public static function getTableName(string $objectName)
    {
        $table = '';
        // make entity lc + underscore
        $words = preg_split(self::PATTERN_SPLIT_UC, lcfirst($objectName));
        foreach($words as $key => $word)
        {
            $table .= $word;
            if(empty($words[$key + 1]) === false)
            {
                $table .= PhpInterface::UNDERSCORE;
            }
        }

        return strtolower($table);
    }
}