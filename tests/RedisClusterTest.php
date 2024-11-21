<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use livenux\RedisCluster;
use think\cache\TagSet;

class RedisClusterTest extends TestCase
{
    /** @var RedisCluster */
    private $redisCluster;

    protected function setUp(): void
    {
        // 配置 RedisCluster 连接参数
        $options = [
            'servers' => ['192.168.117.3:6379'], 
            'timeout' => 1.5,
            'password' => '',
            'prefix' => 'test:',
            'compression' => null, // 默认不使用压缩
        ];
        
        $this->redisCluster = new RedisCluster($options);
    }

    protected function tearDown(): void
    {
        // 清除测试数据
        $this->redisCluster->clear();
    }

    public function testSetAndGet()
    {
        $this->redisCluster->set('key', 'value');
        $this->assertEquals('value', $this->redisCluster->get('key'));
    }

    public function testHas()
    {
        $this->redisCluster->set('key', 'value');
        $this->assertTrue($this->redisCluster->has('key'));
        $this->assertFalse($this->redisCluster->has('nonexistent_key'));
    }

    public function testDelete()
    {
        $this->redisCluster->set('key', 'value');
        $this->assertTrue($this->redisCluster->delete('key'));
        $this->assertFalse($this->redisCluster->has('key'));
    }

    public function testIncrement()
    {
        $this->redisCluster->set('counter', 1);
        $this->redisCluster->inc('counter');
        $this->assertEquals(2, $this->redisCluster->get('counter'));
    }

    public function testDecrement()
    {
        $this->redisCluster->set('counter', 2);
        $this->redisCluster->dec('counter');
        $this->assertEquals(1, $this->redisCluster->get('counter'));
    }

    public function testClear()
    {
        $this->redisCluster->set('key1', 'value1');
        $this->redisCluster->set('key2', 'value2');
        $this->redisCluster->clear();
        $this->assertFalse($this->redisCluster->has('key1'));
        $this->assertFalse($this->redisCluster->has('key2'));
    }

    public function testSetAndGetMultiple()
    {
        $items = ['key1' => 'value1', 'key2' => 'value2'];
        $this->redisCluster->setMultiple($items);
        $results = $this->redisCluster->getMultiple(['key1', 'key2', 'key3'], 'default');
        $this->assertEquals('value1', $results['key1']);
        $this->assertEquals('value2', $results['key2']);
        $this->assertEquals('default', $results['key3']);
    }

    public function testDeleteMultiple()
    {
        $this->redisCluster->set('key1', 'value1');
        $this->redisCluster->set('key2', 'value2');
        $this->redisCluster->deleteMultiple(['key1', 'key2']);
        $this->assertFalse($this->redisCluster->has('key1'));
        $this->assertFalse($this->redisCluster->has('key2'));
    }

    public function testCompressionOptions()
    {
        $compressionAlgorithms = [
            'none' => \Redis::COMPRESSION_NONE,
            'lzf' => defined('\Redis::COMPRESSION_LZF') ? \Redis::COMPRESSION_LZF : null,
            'lz4' => defined('\Redis::COMPRESSION_LZ4') ? \Redis::COMPRESSION_LZ4 : null,
            'zstd' => defined('\Redis::COMPRESSION_ZSTD') ? \Redis::COMPRESSION_ZSTD : null,
        ];

        foreach ($compressionAlgorithms as $name => $constant) {
            if ($constant !== null) {
                echo "Testing compression: $name\n";
                $options = [
                    'servers' => ['192.168.117.3:6379'],
                    'compression' => $name,
                ];
                $redisCluster = new RedisCluster($options);
                $redisCluster->set('key', 'value');
                $this->assertEquals('value', $redisCluster->get('key'));
            } else {
                echo "Compression $name is not supported.\n";
            }
        }
    }

    public function testTagSetOperations()
    {
        $tags = ['tag1', 'tag2'];
        $tagSet = new TagSet($tags, $this->redisCluster);

        // 测试 set 方法
        $tagSet->set('key1', 'value1');
        $this->assertTrue($this->redisCluster->has('key1'));

        // 测试 append 方法
        $items = $this->redisCluster->getTagItems('tag1');
        $this->assertContains($this->redisCluster->getCacheKey('key1'), $items);

        // 测试 clear 方法
        $tagSet->clear();
        $this->assertFalse($this->redisCluster->has('key1'));
        $items = $this->redisCluster->getTagItems('tag1');
        $this->assertEmpty($items);
    }
}