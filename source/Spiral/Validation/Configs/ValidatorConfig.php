<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

namespace Spiral\Validation\Configs;

use Spiral\Core\InjectableConfig;
use Spiral\Core\Traits\Config\AliasTrait;

/**
 * Validation rules and checkers config.
 */
class ValidatorConfig extends InjectableConfig
{
    use AliasTrait;

    /**
     * Configuration section.
     */
    const CONFIG = 'validation';

    /**
     * @var array
     */
    protected $config = [
        'emptyConditions' => [],
        'checkers'        => [],
        'aliases'         => [],
    ];

    /**
     * @param mixed $condition
     *
     * @return bool
     */
    public function emptyCondition($condition): bool
    {
        //Legacy fallback included
        return in_array($condition, $this->config['emptyConditions'])
            || in_array(str_replace(':', '::', $condition), $this->config['emptyConditions']);
    }

    /**
     * @param string $checker
     *
     * @return bool
     */
    public function hasChecker(string $checker): bool
    {
        return isset($this->config['checkers'][$checker]);
    }

    /**
     * @param string $checker
     *
     * @return string|\Spiral\Core\Container\Autowire
     */
    public function checkerClass(string $checker)
    {
        return $this->config['checkers'][$checker];
    }
}
