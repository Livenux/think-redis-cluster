<?php declare(strict_types=1);

namespace livenux;

use think\cache\Driver;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * RedisCluster缓存驱动，适合Redis集群场景
 * 要求安装phpredis扩展：https://github.com/phpredis/phpredis
 */
class RedisCluster extends Driver implements CacheInterface
{
    /** @var \RedisCluster */
    protected $handler;

    /** @var array 配置参数 */
    protected $options = [
        "servers" => [],
        "timeout" => 1.5,
        "read_timeout" => 1.5,
        "expire" => 0,
        "prefix" => "",
        "same_slot_prefix" => false,
        "password" => "",
        "read_type" => "random",
        "persistent" => false,
        "ssl_context" => null,
        "tag_prefix" => "tag:",
    ];

    /**
     * 构造函数
     * @param array $options 缓存参数
     * @throws \BadFunctionCallException
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge($this->options, $options);

        if (!extension_loaded("redis")) {
            throw new \BadFunctionCallException("not support: redis");
        }

        try {
            $this->handler = new \RedisCluster(
                null,
                $this->options["servers"],
                (float) $this->options["timeout"],
                (float) $this->options["read_timeout"],
                $this->options["persistent"],
                $this->options["password"],
            );
        } catch (\Exception $e) {
            throw new \BadFunctionCallException(
                "RedisCluster connect failed: " . $e->getMessage(),
            );
        }

        // 设定从节点故障转移选项
        $this->setSlaveFailover();

        // 用户认证
        if (!empty($this->options["password"])) {
            $this->handler->auth($this->options["password"]);
        }
    }

    private function setSlaveFailover(): void
    {
        switch ($this->options["read_type"]) {
            case "slave":
                $this->handler->setOption(
                    \RedisCluster::OPT_SLAVE_FAILOVER,
                    \RedisCluster::FAILOVER_DISTRIBUTE_SLAVES,
                );
                break;
            case "master":
                $this->handler->setOption(
                    \RedisCluster::OPT_SLAVE_FAILOVER,
                    \RedisCluster::FAILOVER_NONE,
                );
                break;
            case "failover":
                $this->handler->setOption(
                    \RedisCluster::OPT_SLAVE_FAILOVER,
                    \RedisCluster::FAILOVER_ERROR,
                );
                break;
            default:
                $this->handler->setOption(
                    \RedisCluster::OPT_SLAVE_FAILOVER,
                    \RedisCluster::FAILOVER_DISTRIBUTE,
                );
                break;
        }
    }

    /**
     * 生成缓存键
     * @param string $name 缓存变量名
     * @return string
     */
    public function getCacheKey(string $name): string
    {
        $prefix = $this->options["same_slot_prefix"]
            ? "{" . $this->options["prefix"] . "}"
            : $this->options["prefix"];
        return $prefix . $name;
    }

    /**
     * 从主节点或从节点获取数据
     * @param string $key 键名
     * @return mixed
     */
    private function fetch(string $key)
    {
        return $this->handler->get($key);
    }

    /**
     * 判断缓存
     * @param string $key 缓存变量名
     * @return bool
     */
    public function has($key): bool
    {
        $this->validateKey($key);
        return $this->handler->exists($this->getCacheKey($key)) ? true : false;
    }

    /**
     * 读取缓存
     * @param string $key 缓存键
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get($key, $default = null)
    {
        $this->validateKey($key);
        $value = $this->fetch($this->getCacheKey($key));

        return $value !== false ? $this->unserialize($value) : $default;
    }

    /**
     * 写入缓存
     * @param string $key 缓存键
     * @param mixed $value 存储数据
     * @param int|\DateTime|null $ttl 有效时间（秒）
     * @return bool
     */
    public function set($key, $value, $ttl = null): bool
    {
        $this->validateKey($key);
        $key = $this->getCacheKey($key);
        $ttl = $ttl ?? $this->options["expire"];
        $value = $this->serialize($value);

        return $ttl
            ? $this->handler->setex($key, $ttl, $value)
            : $this->handler->set($key, $value);
    }

    /**
     * 自增缓存（针对数值缓存）
     * @param string $key 缓存变量名
     * @param int $step 步长
     * @return int|false
     */
    public function inc(string $key, int $step = 1)
    {
        $this->validateKey($key);
        $key = $this->getCacheKey($key);
        return $this->handler->incrby($key, $step);
    }

    /**
     * 自减缓存（针对数值缓存）
     * @param string $key 缓存变量名
     * @param int $step 步长
     * @return int|false
     */
    public function dec(string $key, int $step = 1)
    {
        $this->validateKey($key);
        $key = $this->getCacheKey($key);
        return $this->handler->decrby($key, $step);
    }

    /**
     * 删除缓存
     * @param string $key 缓存键
     * @return bool
     */
    public function delete($key): bool
    {
        $this->validateKey($key);
        return $this->handler->del($this->getCacheKey($key)) > 0;
    }
    /**
 * 清除所有缓存
 * @return bool
 */
public function clear(): bool
{
    // 获取带前缀的扫描模式
    $prefix = $this->options['prefix'];
    $pattern = $this->getCacheKey('*');
    $keysToDelete = [];

    // 获取所有集群节点
    $masters = $this->handler->_masters(); // 获取所有主节点

    // 遍历所有主节点
    foreach ($masters as $master) {
        $nextCursor = null;

        do {
            // 使用 SCAN 方法逐步获取当前节点的所有匹配的键
	    $scan_node = implode(":", $master);
            $keys = $this->handler->scan($nextCursor, $scan_node, $pattern);
	    
            if (is_array($keys)) {
                $keysToDelete = array_merge($keysToDelete, $keys);
            }
        } while ($nextCursor > 0);
    }

    // 根据 same_slot_prefix 的值决定删除方式
    if ($this->options['same_slot_prefix']) {
        // 一次性删除所有键
        if (!empty($keysToDelete)) {
            $this->handler->del($keysToDelete);
        }
    } else {
        // 循环逐个删除
        foreach ($keysToDelete as $key) {
            $this->handler->del($key);
        }
    }

    return true;
}





    /**
     * 获取多个缓存项
     * @param iterable $keys 缓存键列表
     * @param mixed $default 默认值
     * @return iterable 键值对
     */
    public function getMultiple($keys, $default = null): iterable
    {
        if (!is_array($keys) && !$keys instanceof \Traversable) {
            throw new InvalidArgumentException(
                "Keys must be an array or Traversable.",
            );
        }

        $results = [];
        $prefixedKeys = array_map([$this, "getCacheKey"], $keys);

        if ($this->options["same_slot_prefix"]) {
            // 使用 mget 一次获取所有值
            $values = $this->handler->mGet($prefixedKeys);
            foreach ($keys as $index => $key) {
                $results[$key] =
                    $values[$index] !== false
                        ? $this->unserialize($values[$index])
                        : $default;
            }
        } else {
            // 逐个获取
            foreach ($prefixedKeys as $index => $prefixedKey) {
                $value = $this->handler->get($prefixedKey);
                $results[$keys[$index]] =
                    $value !== false ? $this->unserialize($value) : $default;
            }
        }

        return $results;
    }

    /**
     * 设置多个缓存项
     * @param iterable $values 键值对
     * @param int|\DateTime|null $ttl 有效时间（秒）
     * @return bool
     */
    public function setMultiple($values, $ttl = null): bool
    {
        if (!is_array($values) && !$values instanceof \Traversable) {
            throw new InvalidArgumentException(
                "Values must be an array or Traversable.",
            );
        }

        $ttl = $ttl ?? $this->options["expire"];
        $prefixedValues = [];

        foreach ($values as $key => $value) {
            $prefixedValues[$this->getCacheKey($key)] = $this->serialize(
                $value,
            );
        }

        if ($this->options["same_slot_prefix"]) {
            // 使用 mset 一次设置所有值
            if ($ttl) {
                // 对于有 TTL 的情况，逐个设置
                foreach ($prefixedValues as $key => $serializedValue) {
                    $this->handler->setex($key, $ttl, $serializedValue);
                }
            } else {
                $this->handler->mset($prefixedValues);
            }
        } else {
            // 逐个设置
            foreach ($prefixedValues as $key => $serializedValue) {
                if ($ttl) {
                    $this->handler->setex($key, $ttl, $serializedValue);
                } else {
                    $this->handler->set($key, $serializedValue);
                }
            }
        }

        return true;
    }

    /**
     * 删除多个缓存项
     * @param iterable $keys 缓存键列表
     * @return bool
     */
    public function deleteMultiple($keys): bool
    {
        if (!is_array($keys) && !$keys instanceof \Traversable) {
            throw new InvalidArgumentException(
                "Keys must be an array or Traversable.",
            );
        }

        if ($this->options["same_slot_prefix"]) {
            $prefixedKeys = array_map([$this, "getCacheKey"], $keys);
            // 使用 mdel 批量删除
            return $this->handler->del($prefixedKeys) > 0;
        } else {
            // 逐个删除
            $result = true;
            foreach ($keys as $key) {
                $result = $result && $this->delete($key);
            }
            return $result;
        }
    }

    /**
     * 验证键是否合法
     * @param mixed $key 需要验证的键
     * @throws InvalidArgumentException
     */
    private function validateKey($key): void
    {
        if (!is_string($key)) {
            throw new InvalidArgumentException("The key must be a string.");
        }
    }

    /**
     * 追加TagSet数据
     * @param string $name 缓存标识
     * @param mixed $value 数据
     * @return void
     */
    public function append(string $name, $value): void
    {
        $key = $this->getCacheKey($name);
        $this->handler->sAdd($key, $value);
    }

    /**
     * 获取标签包含的缓存标识
     * @param string $tag 缓存标签
     * @return array
     */
    public function getTagItems(string $tag): array
    {
        $key = $this->getCacheKey($this->getTagKey($tag));
        return $this->handler->sMembers($key);
    }

    /**
     * 删除缓存标签
     * @param array $keys 缓存标识列表
     * @return void
     */
    public function clearTag(array $keys): void
    {
        if ($this->options["same_slot_prefix"]) {
            $prefixedKeys = array_map([$this, "getTagKey"], $keys);
            // 使用 mdel 批量删除
            $this->handler->del($prefixedKeys);
        } else {
            foreach ($keys as $key) {
                $this->handler->del($key); // 单独删除
            }
        }
    }
}
