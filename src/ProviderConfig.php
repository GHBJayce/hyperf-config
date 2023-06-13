<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\Config;

use Hyperf\Di\Definition\PriorityDefinition;
use Hyperf\Support\Composer;

use function class_exists;
use function is_string;
use function method_exists;

/**
 * Provider config allow the components set the configs to application.
 */
class ProviderConfig
{
    private static array $providerConfigs = [];

    /**
     * Load and merge all provider configs from components.
     * Notice that this method will cached the config result into a static property,
     * call ProviderConfig::clear() method if you want to reset the static property.
     */
    public static function load(): array
    {
        if (! static::$providerConfigs) {
            // 从composer.lock中收集所有配置提供者ConfigProvider（hyperf下的config属性）
            $providers = Composer::getMergedExtra('hyperf')['config'] ?? [];
            // 处理成特定的数据格式
            static::$providerConfigs = static::loadProviders($providers);
        }
        return static::$providerConfigs;
    }

    public static function clear(): void
    {
        static::$providerConfigs = [];
    }

    protected static function loadProviders(array $providers): array
    {
        $providerConfigs = [];
        foreach ($providers as $provider) {
            if (is_string($provider) && class_exists($provider) && method_exists($provider, '__invoke')) {
                /*
                 * 以调用函数的方式触发__invoke，取得provider的配置，已知返回的属性有：
                 * 1. dependencies 依赖关系绑定
                 * 2. listeners 监听器，哪个地方会用到？
                 * 3. annotations 注解，哪个地方会用到？
                 * 4. aspects aop切面，哪个地方会用到？
                 * 5. publish 用于命令行生成配置文件
                 */
                $providerConfigs[] = (new $provider())();
            }
        }

        return static::merge(...$providerConfigs);
    }

    /**
     * 最终返回结构：[
     *  //多个ConfigProvider的dependencies组合在一起，其他属性也一样
     *  'dependencies' => [
     *      CacheInterface::class => Cache::class,
     *      MethodDefinitionCollectorInterface::class => MethodDefinitionCollector::class,
     *      ...
     *  ],
     *  'aspects' => [
     *      InjectAspect::class,
     *      TransactionAspect::class,
     *      ...
     *  ],
     *  ...
     * ]
     */
    protected static function merge(...$arrays): array
    {
        if (empty($arrays)) {
            return [];
        }
        $result = array_merge_recursive(...$arrays);
        if (isset($result['dependencies'])) {
            $result['dependencies'] = [];
            foreach ($arrays as $item) {
                foreach ($item['dependencies'] ?? [] as $key => $value) {
                    $depend = $result['dependencies'][$key] ?? null;
                    if (! $depend instanceof PriorityDefinition) {
                        $result['dependencies'][$key] = $value;
                        continue;
                    }

                    if ($value instanceof PriorityDefinition) {
                        $depend->merge($value);
                    }
                }
            }
        }

        return $result;
    }
}
