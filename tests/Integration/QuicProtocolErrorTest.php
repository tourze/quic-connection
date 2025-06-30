<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * QUIC 协议错误测试
 * 
 * 测试各种错误条件和异常情况的处理
 */
class QuicProtocolErrorTest extends TestCase
{
    /**
     * 测试格式错误的包
     */
    public function testMalformedPackets(): void
    {
        echo "\n=== 测试格式错误的包 ===\n";
        
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
        
        $malformedPackets = [
            'empty' => [
                'data' => '',
                'description' => '空包'
            ],
            'too_short' => [
                'data' => chr(0xc0),
                'description' => '太短（只有1字节）'
            ],
            'invalid_header' => [
                'data' => chr(0x00) . random_bytes(100),
                'description' => '无效的头部格式'
            ],
            'truncated_version' => [
                'data' => chr(0xc0) . chr(0x00) . chr(0x00),
                'description' => '版本字段被截断'
            ],
            'missing_cid' => [
                'data' => chr(0xc0) . pack('N', 0x00000001),
                'description' => '缺少连接ID'
            ],
            'invalid_cid_length' => [
                'data' => chr(0xc0) . pack('N', 0x00000001) . chr(255),
                'description' => '无效的CID长度'
            ],
            'random_garbage' => [
                'data' => random_bytes(100),
                'description' => '随机垃圾数据'
            ],
            'partial_initial' => [
                'data' => substr($this->buildValidInitialPacket(), 0, 50),
                'description' => '部分Initial包'
            ],
        ];
        
        $googleIp = gethostbyname('www.google.com');
        
        foreach ($malformedPackets as $type => $packet) {
            echo "\n测试: {$packet['description']}\n";
            echo "  数据长度: " . strlen($packet['data']) . " 字节\n";
            
            if (strlen($packet['data']) > 0) {
                echo "  前16字节: " . bin2hex(substr($packet['data'], 0, 16)) . "\n";
            }
            
            $bytesSent = @socket_sendto(
                $socket, 
                $packet['data'], 
                strlen($packet['data']), 
                0, 
                $googleIp, 
                443
            );
            
            if ($bytesSent === false) {
                echo "  发送失败\n";
            } else {
                echo "  发送: {$bytesSent} 字节\n";
                
                $buffer = '';
                $from = '';
                $port = 0;
                $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, MSG_DONTWAIT, $from, $port);
                
                if ($bytesReceived > 0) {
                    echo "  ⚠️ 收到响应: {$bytesReceived} 字节\n";
                    echo "  服务器不应该响应格式错误的包\n";
                } else {
                    echo "  ✅ 无响应（预期行为）\n";
                }
            }
            
            usleep(100000); // 100ms
        }
        
        socket_close($socket);
        $this->assertTrue(true);
    }
    
    /**
     * 测试无效版本处理
     */
    public function testInvalidVersionHandling(): void
    {
        echo "\n=== 测试无效版本处理 ===\n";
        
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);
        
        $versions = [
            0x00000000 => '版本协商（客户端不应发送）',
            0x00000002 => '未定义的版本2',
            0x0a0a0a0a => 'GREASE版本',
            0x1a2a3a4a => 'GREASE版本模式',
            0xffffffff => '最大版本号',
            0xfaceb00c => 'Facebook内部版本',
            0xdeadbeef => '测试版本',
            0x51474943 => 'QUIC的ASCII码',
        ];
        
        $googleIp = gethostbyname('www.google.com');
        $results = [];
        
        foreach ($versions as $version => $description) {
            echo "\n测试版本 0x" . sprintf('%08x', $version) . " ({$description})\n";
            
            $packet = $this->buildPacketWithVersion($version);
            $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);
            
            if ($bytesSent !== false) {
                echo "  发送成功: {$bytesSent} 字节\n";
                
                $buffer = '';
                $from = '';
                $port = 0;
                $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, 0, $from, $port);
                
                if ($bytesReceived > 0) {
                    echo "  收到响应: {$bytesReceived} 字节\n";
                    
                    if ($bytesReceived >= 5) {
                        $responseVersion = unpack('N', substr($buffer, 1, 4))[1];
                        if ($responseVersion === 0) {
                            echo "  ✅ 收到版本协商响应\n";
                            $results[$version] = 'version_negotiation';
                        } else {
                            echo "  收到其他响应，版本: 0x" . sprintf('%08x', $responseVersion) . "\n";
                            $results[$version] = 'other_response';
                        }
                    }
                } else {
                    echo "  无响应\n";
                    $results[$version] = 'no_response';
                }
            }
            
            usleep(200000); // 200ms
        }
        
        // 分析结果
        echo "\n=== 版本处理总结 ===\n";
        $versionNegotiations = array_filter($results, fn($r) => $r === 'version_negotiation');
        echo "触发版本协商的版本数: " . count($versionNegotiations) . "\n";
        
        $this->assertGreaterThan(0, count($versionNegotiations), "至少应该有一个版本触发版本协商");
        
        socket_close($socket);
    }
    
    /**
     * 测试包注入攻击
     */
    public function testPacketInjectionAttacks(): void
    {
        echo "\n=== 测试包注入攻击场景 ===\n";
        
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
        
        $googleIp = gethostbyname('www.google.com');
        
        // 攻击场景
        $attacks = [
            'duplicate_initial' => [
                'description' => '重复的Initial包',
                'packets' => function() {
                    $packet = $this->buildValidInitialPacket();
                    return [$packet, $packet]; // 发送两次相同的包
                }
            ],
            'conflicting_cids' => [
                'description' => '冲突的连接ID',
                'packets' => function() {
                    $dcid = random_bytes(8);
                    $packet1 = $this->buildPacketWithCids($dcid, random_bytes(8));
                    $packet2 = $this->buildPacketWithCids($dcid, random_bytes(8)); // 相同DCID，不同SCID
                    return [$packet1, $packet2];
                }
            ],
            'packet_reordering' => [
                'description' => '包乱序',
                'packets' => function() {
                    $packets = [];
                    for ($i = 5; $i >= 1; $i--) {
                        $packets[] = $this->buildPacketWithPacketNumber($i, 1);
                    }
                    return $packets;
                }
            ],
            'rapid_fire' => [
                'description' => '快速发送大量包',
                'packets' => function() {
                    $packets = [];
                    for ($i = 0; $i < 10; $i++) {
                        $packets[] = $this->buildValidInitialPacket();
                    }
                    return $packets;
                }
            ],
        ];
        
        foreach ($attacks as $type => $attack) {
            echo "\n测试: {$attack['description']}\n";
            
            $packets = $attack['packets']();
            $responsesReceived = 0;
            
            foreach ($packets as $i => $packet) {
                $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);
                if ($bytesSent !== false) {
                    echo "  发送包 #" . ($i + 1) . ": {$bytesSent} 字节\n";
                }
                
                // 快速检查响应
                $buffer = '';
                $from = '';
                $port = 0;
                $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, MSG_DONTWAIT, $from, $port);
                
                if ($bytesReceived > 0) {
                    $responsesReceived++;
                }
                
                usleep(10000); // 10ms
            }
            
            echo "  收到响应数: {$responsesReceived}\n";
            
            usleep(200000); // 200ms between attacks
        }
        
        socket_close($socket);
        $this->assertTrue(true);
    }
    
    /**
     * 测试协议降级攻击
     */
    public function testProtocolDowngradeAttacks(): void
    {
        echo "\n=== 测试协议降级攻击 ===\n";
        
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);
        
        $googleIp = gethostbyname('www.google.com');
        
        // 1. 首先获取服务器支持的版本
        echo "步骤1: 获取服务器支持的版本\n";
        $packet = $this->buildPacketWithVersion(0xdeadbeef);
        @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);
        
        $buffer = '';
        $from = '';
        $port = 0;
        $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, 0, $from, $port);
        
        $supportedVersions = [];
        if ($bytesReceived > 0 && strlen($buffer) >= 5) {
            $version = unpack('N', substr($buffer, 1, 4))[1];
            if ($version === 0) {
                $supportedVersions = $this->extractVersionsFromPacket($buffer);
                echo "  服务器支持的版本: " . implode(', ', array_map(fn($v) => sprintf('0x%08x', $v), $supportedVersions)) . "\n";
            }
        }
        
        // 2. 尝试降级攻击
        echo "\n步骤2: 尝试强制使用旧版本\n";
        
        if (!empty($supportedVersions)) {
            // 使用最旧的支持版本
            $oldestVersion = $supportedVersions[count($supportedVersions) - 1];
            echo "  尝试使用版本: 0x" . sprintf('%08x', $oldestVersion) . "\n";
            
            $packet = $this->buildPacketWithVersion($oldestVersion);
            $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);
            
            if ($bytesSent !== false) {
                echo "  发送成功: {$bytesSent} 字节\n";
                
                $buffer = '';
                $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, 0, $from, $port);
                
                if ($bytesReceived > 0) {
                    echo "  收到响应: {$bytesReceived} 字节\n";
                    $this->analyzeResponse($buffer);
                }
            }
        }
        
        socket_close($socket);
        $this->assertTrue(true);
    }
    
    /**
     * 测试资源耗尽攻击
     */
    public function testResourceExhaustionAttacks(): void
    {
        echo "\n=== 测试资源耗尽攻击 ===\n";
        
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 0, 'usec' => 100000]); // 100ms
        socket_set_nonblock($socket);
        
        $googleIp = gethostbyname('www.google.com');
        
        // 测试场景
        $scenarios = [
            'many_connections' => [
                'description' => '大量不同连接',
                'count' => 50,
                'generator' => function() {
                    return $this->buildValidInitialPacket(); // 每次生成新的CID
                }
            ],
            'large_tokens' => [
                'description' => '大令牌攻击',
                'count' => 10,
                'generator' => function() {
                    return $this->buildPacketWithLargeToken(1000);
                }
            ],
            'max_size_packets' => [
                'description' => '最大尺寸包',
                'count' => 20,
                'generator' => function() {
                    return $this->buildMaxSizePacket();
                }
            ],
        ];
        
        foreach ($scenarios as $type => $scenario) {
            echo "\n测试: {$scenario['description']} (发送 {$scenario['count']} 个包)\n";
            
            $sent = 0;
            $responses = 0;
            $startTime = microtime(true);
            
            for ($i = 0; $i < $scenario['count']; $i++) {
                $packet = $scenario['generator']();
                $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);
                
                if ($bytesSent !== false) {
                    $sent++;
                }
                
                // 非阻塞接收
                $buffer = '';
                $from = '';
                $port = 0;
                $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, MSG_DONTWAIT, $from, $port);
                
                if ($bytesReceived > 0) {
                    $responses++;
                }
                
                // 避免过快发送
                usleep(5000); // 5ms
            }
            
            $duration = microtime(true) - $startTime;
            echo "  发送: {$sent} 个包\n";
            echo "  响应: {$responses} 个\n";
            echo "  耗时: " . round($duration, 3) . " 秒\n";
            echo "  速率: " . round($sent / $duration) . " 包/秒\n";
            
            // 等待剩余响应
            usleep(500000); // 500ms
        }
        
        socket_close($socket);
        $this->assertTrue(true);
    }
    
    /**
     * 构建有效的Initial包
     */
    private function buildValidInitialPacket(): string
    {
        return $this->buildPacketWithVersion(0x00000001);
    }
    
    /**
     * 构建指定版本的包
     */
    private function buildPacketWithVersion(int $version): string
    {
        $packet = '';
        
        $packet .= chr(0xc0);
        $packet .= pack('N', $version);
        
        $dcidLen = 8;
        $packet .= chr($dcidLen) . random_bytes($dcidLen);
        
        $scidLen = 8;
        $packet .= chr($scidLen) . random_bytes($scidLen);
        
        $packet .= chr(0);
        
        $remainingLength = 1200 - strlen($packet) - 2;
        $packet .= $this->encodeVariableLengthInteger($remainingLength);
        
        $packet .= chr(0);
        
        return str_pad($packet, 1200, "\x00");
    }
    
    /**
     * 构建指定CID的包
     */
    private function buildPacketWithCids(string $dcid, string $scid): string
    {
        $packet = '';
        
        $packet .= chr(0xc0);
        $packet .= pack('N', 0x00000001);
        
        $packet .= chr(strlen($dcid)) . $dcid;
        $packet .= chr(strlen($scid)) . $scid;
        
        $packet .= chr(0);
        
        $remainingLength = 1200 - strlen($packet) - 2;
        $packet .= $this->encodeVariableLengthInteger($remainingLength);
        
        $packet .= chr(0);
        
        return str_pad($packet, 1200, "\x00");
    }
    
    /**
     * 构建指定包号的包
     */
    private function buildPacketWithPacketNumber(int $pn, int $length): string
    {
        $packet = '';
        
        $headerByte = 0xc0 | ($length - 1);
        $packet .= chr($headerByte);
        
        $packet .= pack('N', 0x00000001);
        
        $packet .= chr(8) . random_bytes(8);
        $packet .= chr(8) . random_bytes(8);
        
        $packet .= chr(0);
        
        $remainingLength = 1200 - strlen($packet) - 2 - $length;
        $packet .= $this->encodeVariableLengthInteger($remainingLength);
        
        if ($length == 1) {
            $packet .= chr($pn & 0xFF);
        } elseif ($length == 2) {
            $packet .= pack('n', $pn & 0xFFFF);
        } elseif ($length == 4) {
            $packet .= pack('N', $pn);
        }
        
        return str_pad($packet, 1200, "\x00");
    }
    
    /**
     * 构建带大令牌的包
     */
    private function buildPacketWithLargeToken(int $tokenSize): string
    {
        $packet = '';
        
        $packet .= chr(0xc0);
        $packet .= pack('N', 0x00000001);
        
        $packet .= chr(8) . random_bytes(8);
        $packet .= chr(8) . random_bytes(8);
        
        $packet .= $this->encodeVariableLengthInteger($tokenSize);
        $packet .= random_bytes($tokenSize);
        
        $currentLength = strlen($packet);
        if ($currentLength < 1200) {
            $remainingLength = 1200 - $currentLength - 2;
            $packet .= $this->encodeVariableLengthInteger($remainingLength);
            $packet .= chr(0);
            $packet = str_pad($packet, 1200, "\x00");
        } else {
            $packet .= $this->encodeVariableLengthInteger(1);
            $packet .= chr(0);
        }
        
        return $packet;
    }
    
    /**
     * 构建最大尺寸包
     */
    private function buildMaxSizePacket(): string
    {
        $packet = '';
        
        $packet .= chr(0xc0);
        $packet .= pack('N', 0x00000001);
        
        $packet .= chr(8) . random_bytes(8);
        $packet .= chr(8) . random_bytes(8);
        
        $packet .= chr(0);
        
        // 填充到接近UDP最大值
        $targetSize = 65000;
        $remainingLength = $targetSize - strlen($packet) - 8;
        $packet .= $this->encodeVariableLengthInteger($remainingLength);
        
        $packet .= chr(0);
        $packet .= random_bytes($remainingLength - 1);
        
        return $packet;
    }
    
    /**
     * 从包中提取版本
     */
    private function extractVersionsFromPacket(string $buffer): array
    {
        $versions = [];
        $pos = 5;
        
        // 跳过DCID
        if ($pos < strlen($buffer)) {
            $dcidLen = ord($buffer[$pos]);
            $pos += 1 + $dcidLen;
        }
        
        // 跳过SCID
        if ($pos < strlen($buffer)) {
            $scidLen = ord($buffer[$pos]);
            $pos += 1 + $scidLen;
        }
        
        // 读取版本
        while ($pos + 4 <= strlen($buffer)) {
            $version = unpack('N', substr($buffer, $pos, 4))[1];
            $versions[] = $version;
            $pos += 4;
        }
        
        return $versions;
    }
    
    /**
     * 分析响应
     */
    private function analyzeResponse(string $buffer): void
    {
        if (strlen($buffer) < 1) {
            return;
        }
        
        $firstByte = ord($buffer[0]);
        $isLongHeader = ($firstByte & 0x80) === 0x80;
        
        echo "    响应分析:\n";
        echo "    - 长头部: " . ($isLongHeader ? '是' : '否') . "\n";
        
        if ($isLongHeader && strlen($buffer) >= 5) {
            $version = unpack('N', substr($buffer, 1, 4))[1];
            echo "    - 版本: 0x" . sprintf('%08x', $version) . "\n";
            
            if ($version !== 0) {
                $packetType = ($firstByte & 0x30) >> 4;
                $types = ['Initial', '0-RTT', 'Handshake', 'Retry'];
                echo "    - 包类型: " . $types[$packetType] . "\n";
            }
        }
    }
    
    /**
     * 编码变长整数
     */
    private function encodeVariableLengthInteger(int $value): string
    {
        if ($value <= 63) {
            return chr($value);
        } elseif ($value <= 16383) {
            return pack('n', 0x4000 | $value);
        } elseif ($value <= 1073741823) {
            return pack('N', 0x80000000 | $value);
        } else {
            return pack('J', 0xc000000000000000 | $value);
        }
    }
}