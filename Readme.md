
# ThinkPHP Redis Cluser Cache Driver
---
ThinkPHP Redis 集群模式支持

## 安装
``` shell
composer require livenux/think-redis-cluster
```

## 用法
编辑 thinkphp 配置文件 `conf/cache.php`
``` php
<?php

return [
    "default" => "redis_cluster",
    "stores" => [
        "redis_cluster" => [
            "type" => "livenux\\RedisCluster",
            "servers" => ["127.0.0.1:7000", "127.0.0.1:7001", "127.0.0.1:7002"],
            "read_type" =>  "failover", // 读写分离选项, key的读写类型, 默认random, 可选: random, slaves, master, failover, 建议采用 failover 主从延迟的情况下 thinkphp 会报错
            "timeout" => 1.5, // 连接超时时间
            "read_timeout" => 1.5, // 读超时时间
            "expire" => 7200, // 缓存有效时间
            "prefix" => 　"think:", // 缓存前缀
            "password" => '', // 用户认证密码
            "ssl" => false, // 是否使用SSL
            "persistent" => true, // 是否使用持久化连接
            "ssl_context" => null, // SSL上下文选项
            "compression" => "lz4", // 压缩选项, 可选: lzf, lz4, zstd, 默认不压缩
        ],
    ],

];

```

## 问题
1. 在 Redis 集群主从延迟的情况下，ThinkPHP 在设置了缓存之后会因为读不到缓存而报错，建议 `read_type` 采用 `failover` 或者 `master` 只在主节点读写，从节点作为故障转移节点， 或者在代码层面优化.
2. ssl 支持，为了兼容 php redis 扩展 5.1  没有实现 ssl 连接功能
