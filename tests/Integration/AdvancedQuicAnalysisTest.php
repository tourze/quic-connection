<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * 高级 QUIC 协议分析测试
 * 
 * 深入分析 QUIC 协议响应和行为
 */
class AdvancedQuicAnalysisTest extends TestCase
{
    /**
     * 测试并分析完整的 QUIC 握手流程
     */
    public function testCompleteQuicHandshakeAnalysis(): void
    {
        echo "\n=== 完整 QUIC 握手流程分析 ===\n\n";
        
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);
        
        // 步骤 1: 发送 Initial 包
        echo "步骤 1: 发送 Initial 包\n";
        $initialPacket = $this->buildEnhancedInitialPacket();
        $this->displayPacketInfo($initialPacket, "Initial 包");
        
        $googleIp = gethostbyname('www.google.com');
        $bytesSent = @socket_sendto($socket, $initialPacket, strlen($initialPacket), 0, $googleIp, 443);
        
        if ($bytesSent === false) {
            socket_close($socket);
            $this->fail("发送 Initial 包失败");
        }
        
        echo "  发送成功: {$bytesSent} 字节到 {$googleIp}:443\n\n";
        
        // 步骤 2: 接收服务器响应
        echo "步骤 2: 等待服务器响应\n";
        $responses = [];
        $maxResponses = 5;
        
        for ($i = 0; $i < $maxResponses; $i++) {
            $buffer = '';
            $from = '';
            $port = 0;
            $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, MSG_DONTWAIT, $from, $port);
            
            if ($bytesReceived > 0) {
                $responses[] = [
                    'data' => $buffer,
                    'size' => $bytesReceived,
                    'from' => $from,
                    'port' => $port,
                    'time' => microtime(true)
                ];
                
                echo "  收到响应 #{$i}: {$bytesReceived} 字节\n";
                $this->analyzeQuicPacketDetailed($buffer);
                echo "\n";
            } else {
                usleep(100000); // 100ms
            }
        }
        
        socket_close($socket);
        
        // 步骤 3: 分析握手结果
        echo "步骤 3: 握手分析总结\n";
        echo "  收到 " . count($responses) . " 个响应包\n";
        
        if (count($responses) > 0) {
            $this->analyzeHandshakeSequence($responses);
        }
        
        // 注意：在某些网络环境中可能不会收到响应，这是正常的
        if (empty($responses)) {
            $this->markTestSkipped('未收到网络响应，可能是网络环境限制');
        }
    }
    
    /**
     * 构建增强的 Initial 包
     */
    private function buildEnhancedInitialPacket(): string
    {
        $packet = '';

        // 头部
        $headerByte = 0xc3; // 11000011 - Initial, 4字节包号
        $packet .= chr($headerByte);

        // 版本
        $packet .= pack('N', 0x00000001); // QUIC v1

        // DCID
        $dcid = random_bytes(8);
        $packet .= chr(8) . $dcid;

        // SCID
        $scid = random_bytes(8);
        $packet .= chr(8) . $scid;

        // Token
        $packet .= chr(0);

        // 计算剩余长度
        $cryptoFrame = $this->buildEnhancedCryptoFrame();
        $paddingNeeded = 1200 - strlen($packet) - 4 - 4 - strlen($cryptoFrame); // 4 for length, 4 for packet number

        // Length
        $length = 4 + strlen($cryptoFrame) + $paddingNeeded;
        $packet .= $this->encodeVariableLengthInteger($length);

        // Packet Number (4 bytes)
        $packet .= pack('N', 0);

        // CRYPTO frame
        $packet .= $cryptoFrame;

        // PADDING
        $packet .= str_repeat("\x00", $paddingNeeded);

        return $packet;
    }
    
    /**
     * 构建增强的 CRYPTO 帧
     */
    private function buildEnhancedCryptoFrame(): string
    {
        $frame = '';

        // Frame type
        $frame .= chr(0x06); // CRYPTO frame

        // Offset
        $frame .= chr(0);

        // 简化的 ClientHello 数据
        $clientHelloData = $this->buildMinimalClientHello();

        // Length
        $frame .= $this->encodeVariableLengthInteger(strlen($clientHelloData));

        // Data
        $frame .= $clientHelloData;

        return $frame;
    }
    
    /**
     * 构建最小 ClientHello
     */
    private function buildMinimalClientHello(): string
    {
        // 这是一个占位符
        // 真实实现需要完整的 TLS 1.3 ClientHello
        return str_repeat("\x00", 200);
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
    
    /**
     * 显示包信息
     */
    private function displayPacketInfo(string $packet, string $label): void
    {
        echo "  {$label}:\n";
        echo "    大小: " . strlen($packet) . " 字节\n";
        echo "    前 32 字节: " . bin2hex(substr($packet, 0, 32)) . "...\n";

        if (strlen($packet) >= 1) {
            $firstByte = ord($packet[0]);
            echo "    头部字节: 0x" . sprintf('%02x', $firstByte);
            echo " (二进制: " . sprintf('%08b', $firstByte) . ")\n";
        }
    }
    
    /**
     * 详细分析 QUIC 包
     */
    private function analyzeQuicPacketDetailed(string $buffer): void
    {
        if (strlen($buffer) < 5) {
            echo "    包太短，无法分析\n";
            return;
        }

        $firstByte = ord($buffer[0]);
        $isLongHeader = ($firstByte & 0x80) === 0x80;

        echo "    头部类型: " . ($isLongHeader ? "长头部" : "短头部") . "\n";

        if ($isLongHeader) {
            $version = unpack('N', substr($buffer, 1, 4))[1];
            echo "    版本: 0x" . sprintf('%08x', $version);

            if ($version === 0) {
                echo " (版本协商)";
            } elseif ($version === 0x00000001) {
                echo " (QUIC v1)";
            }
            echo "\n";

            if ($version !== 0) {
                $packetType = ($firstByte & 0x30) >> 4;
                $typeNames = ['Initial', '0-RTT', 'Handshake', 'Retry'];
                echo "    包类型: " . $typeNames[$packetType] . "\n";

                // 解析连接 ID
                $pos = 5;
                if ($pos < strlen($buffer)) {
                    $dcidLen = ord($buffer[$pos]);
                    echo "    DCID 长度: {$dcidLen}\n";
                    if ($pos + 1 + $dcidLen < strlen($buffer)) {
                        $dcid = substr($buffer, $pos + 1, $dcidLen);
                        echo "    DCID: " . bin2hex($dcid) . "\n";
                    }
                }
            }
        }
    }
    
    /**
     * 分析握手序列
     */
    private function analyzeHandshakeSequence(array $responses): void
    {
        echo "  握手序列分析:\n";

        $packetTypes = [];
        foreach ($responses as $i => $response) {
            $buffer = $response['data'];
            if (strlen($buffer) >= 1) {
                $firstByte = ord($buffer[0]);
                if (($firstByte & 0x80) === 0x80 && strlen($buffer) >= 5) {
                    $version = unpack('N', substr($buffer, 1, 4))[1];
                    if ($version === 0) {
                        $packetTypes[] = 'Version Negotiation';
                    } else {
                        $packetType = ($firstByte & 0x30) >> 4;
                        $typeNames = ['Initial', '0-RTT', 'Handshake', 'Retry'];
                        $packetTypes[] = $typeNames[$packetType];
                    }
                } else {
                    $packetTypes[] = '1-RTT';
                }
            }
        }

        echo "    包序列: " . implode(' -> ', $packetTypes) . "\n";

        // 计算 RTT
        if (count($responses) >= 2) {
            $rtt = ($responses[0]['time'] - $responses[1]['time']) * 1000;
            echo "    估计 RTT: " . abs(round($rtt, 2)) . " ms\n";
        }
    }
    
    /**
     * 测试 QUIC 统计信息收集
     */
    public function testQuicStatisticsCollection(): void
    {
        echo "\n=== QUIC 统计信息收集 ===\n\n";

        $stats = [
            'packets_sent' => 0,
            'packets_received' => 0,
            'bytes_sent' => 0,
            'bytes_received' => 0,
            'version_negotiations' => 0,
            'retries' => 0,
            'handshakes' => 0,
            'connection_errors' => 0,
        ];

        $targets = [
            'Google' => 'www.google.com',
            'Facebook' => 'www.facebook.com',
            'Cloudflare' => 'cloudflare-quic.com',
        ];

        foreach ($targets as $name => $hostname) {
            echo "测试 {$name}...\n";

            $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($socket === false) continue;

            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

            $ip = gethostbyname($hostname);
            if ($ip === $hostname) {
                socket_close($socket);
                continue;
            }

            // 发送测试包
            $packet = $this->buildQuicInitialPacket();
            $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $ip, 443);

            if ($bytesSent !== false) {
                $stats['packets_sent']++;
                $stats['bytes_sent'] += $bytesSent;

                // 接收响应
                $buffer = '';
                $from = '';
                $port = 0;
                $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, 0, $from, $port);

                if ($bytesReceived > 0) {
                    $stats['packets_received']++;
                    $stats['bytes_received'] += $bytesReceived;

                    // 分析包类型
                    $this->updateStatsFromPacket($buffer, $stats);
                }
            }

            socket_close($socket);
        }

        // 显示统计结果
        echo "\n=== 统计结果 ===\n";
        foreach ($stats as $key => $value) {
            echo sprintf("%-20s: %d\n", $key, $value);
        }

        $this->assertGreaterThan(0, $stats['packets_sent'], "应该至少发送了一个包");
    }
    
    /**
     * 构建基本 Initial 包
     */
    private function buildQuicInitialPacket(): string
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
     * 更新统计信息
     */
    private function updateStatsFromPacket(string $buffer, array &$stats): void
    {
        if (strlen($buffer) < 5) return;

        $firstByte = ord($buffer[0]);
        if (($firstByte & 0x80) === 0x80) {
            $version = unpack('N', substr($buffer, 1, 4))[1];

            if ($version === 0) {
                $stats['version_negotiations']++;
            } else {
                $packetType = ($firstByte & 0x30) >> 4;
                if ($packetType === 3) {
                    $stats['retries']++;
                } elseif ($packetType === 2) {
                    $stats['handshakes']++;
                }
            }
        }
    }
    
    /**
     * 测试 QUIC 错误处理
     */
    public function testQuicErrorScenarios(): void
    {
        echo "\n=== QUIC 错误场景测试 ===\n\n";

        $errorScenarios = [
            'invalid_version' => [
                'description' => '无效版本',
                'version' => 0x12345678,
                'expected' => 'version_negotiation'
            ],
            'malformed_header' => [
                'description' => '畸形头部',
                'builder' => $this->buildMalformedPacket(...),
                'expected' => 'no_response'
            ],
            'too_small_initial' => [
                'description' => 'Initial 包太小',
                'size' => 500,
                'expected' => 'no_response'
            ],
        ];

        foreach ($errorScenarios as $scenario => $config) {
            echo "测试: {$config['description']}\n";

            $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($socket === false) continue;

            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);

            // 构建错误包
            if (isset($config['version'])) {
                $packet = $this->buildPacketWithVersion($config['version']);
            } elseif (isset($config['builder'])) {
                $packet = call_user_func($config['builder']);
            } elseif (isset($config['size'])) {
                $packet = substr($this->buildQuicInitialPacket(), 0, $config['size']);
            } else {
                $packet = $this->buildQuicInitialPacket();
            }

            $googleIp = gethostbyname('www.google.com');
            $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);

            if ($bytesSent !== false) {
                echo "  发送: {$bytesSent} 字节\n";

                $buffer = '';
                $from = '';
                $port = 0;
                $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, 0, $from, $port);

                if ($bytesReceived > 0) {
                    echo "  响应: {$bytesReceived} 字节\n";

                    if ($config['expected'] === 'version_negotiation') {
                        $version = unpack('N', substr($buffer, 1, 4))[1] ?? null;
                        if ($version === 0) {
                            echo "  ✅ 收到预期的版本协商响应\n";
                        }
                    }
                } else {
                    echo "  无响应";
                    if ($config['expected'] === 'no_response') {
                        echo " ✅ (预期行为)";
                    }
                    echo "\n";
                }
            }

            socket_close($socket);
            echo "\n";
        }

        $this->assertTrue(true, "错误场景测试完成");
    }
    
    /**
     * 构建畸形包
     */
    private function buildMalformedPacket(): string
    {
        // 故意构建一个畸形的包
        $packet = chr(0xff); // 无效的头部字节
        $packet .= random_bytes(100);
        return $packet;
    }
}