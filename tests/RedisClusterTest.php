<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use livenux\RedisCluster;

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
        ];
        
        $this->redisCluster = new RedisCluster($options);
    }

    protected function tearDown(): void
    {
        // 清除测试数据
        $this->redisCluster->clear();
        // Optionally, you can close the connection if your implementation supports it
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
}

