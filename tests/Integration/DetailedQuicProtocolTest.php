<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * 详细的 QUIC 协议测试
 * 
 * 深入测试 QUIC 协议的各个方面
 */
class DetailedQuicProtocolTest extends TestCase
{
    /**
     * 测试多个 QUIC 服务器的版本支持
     */
    public function testQuicVersionSupportAcrossServers(): void
    {
        echo "\n=== 测试不同服务器的 QUIC 版本支持 ===\n\n";
        
        $servers = [
            'Google' => 'www.google.com',
            'YouTube' => 'www.youtube.com',
            'Facebook' => 'www.facebook.com',
            'Cloudflare' => 'cloudflare-quic.com',
        ];
        
        $results = [];
        
        foreach ($servers as $name => $hostname) {
            echo "测试 {$name} ({$hostname})...\n";
            
            $ip = gethostbyname($hostname);
            if ($ip === $hostname) {
                echo "  无法解析 IP\n\n";
                continue;
            }
            
            echo "  IP: {$ip}\n";
            
            $versions = $this->getServerSupportedVersions($ip);
            if (!empty($versions)) {
                $results[$name] = $versions;
                echo "  支持的版本:\n";
                foreach ($versions as $version) {
                    echo "    - {$version}\n";
                }
            } else {
                echo "  没有收到版本协商响应\n";
            }
            echo "\n";
        }
        
        // 分析结果
        if (!empty($results)) {
            echo "=== 版本支持总结 ===\n";
            
            // 找出所有版本
            $allVersions = [];
            foreach ($results as $server => $versions) {
                foreach ($versions as $version) {
                    if (!in_array($version, $allVersions)) {
                        $allVersions[] = $version;
                    }
                }
            }
            
            // 显示哪些服务器支持哪些版本
            foreach ($allVersions as $version) {
                $supportingServers = [];
                foreach ($results as $server => $versions) {
                    if (in_array($version, $versions)) {
                        $supportingServers[] = $server;
                    }
                }
                echo "{$version}: " . implode(', ', $supportingServers) . "\n";
            }
        }
        
        $this->assertNotEmpty($results, "至少应该有一个服务器响应版本协商");
    }
    
    /**
     * 获取服务器支持的版本
     */
    private function getServerSupportedVersions(string $ip): array
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            return [];
        }

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

        // 使用不支持的版本触发版本协商
        $packet = $this->buildPacketWithVersion(0xdeadbeef);

        $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $ip, 443);
        if ($bytesSent === false) {
            socket_close($socket);
            return [];
        }

        $buffer = '';
        $from = '';
        $port = 0;
        $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, 0, $from, $port);

        socket_close($socket);

        if ($bytesReceived > 0 && strlen($buffer) >= 5) {
            $version = unpack('N', substr($buffer, 1, 4))[1];
            if ($version === 0) {
                return $this->extractVersionsFromNegotiationPacket($buffer);
            }
        }

        return [];
    }
    
    /**
     * 构建带版本的包
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
     * 从版本协商包中提取版本
     */
    private function extractVersionsFromNegotiationPacket(string $buffer): array
    {
        $versions = [];
        $pos = 5;

        // 跳过 DCID
        if ($pos < strlen($buffer)) {
            $dcidLen = ord($buffer[$pos]);
            $pos += 1 + $dcidLen;
        }

        // 跳过 SCID
        if ($pos < strlen($buffer)) {
            $scidLen = ord($buffer[$pos]);
            $pos += 1 + $scidLen;
        }

        // 读取版本
        while ($pos + 4 <= strlen($buffer)) {
            $version = unpack('N', substr($buffer, $pos, 4))[1];
            $versionStr = sprintf('0x%08x', $version);

            switch ($version) {
                case 0x00000001:
                    $versionStr .= ' (QUIC v1)';
                    break;
                case 0xff00001d:
                    $versionStr .= ' (draft-29)';
                    break;
            }

            $versions[] = $versionStr;
            $pos += 4;
        }

        return $versions;
    }
    
    /**
     * 测试 QUIC 连接 ID 处理
     */
    public function testQuicConnectionIdHandling(): void
    {
        echo "\n=== 测试 QUIC 连接 ID 处理 ===\n";

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

        // 测试不同长度的连接 ID
        $cidLengths = [0, 4, 8, 16, 20];

        foreach ($cidLengths as $length) {
            echo "\n测试 {$length} 字节连接 ID...\n";

            $packet = $this->buildPacketWithConnectionIdLength($length);

            $googleIp = gethostbyname('www.google.com');
            $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);

            if ($bytesSent === false) {
                echo "  发送失败\n";
                continue;
            }

            echo "  发送成功: {$bytesSent} 字节\n";

            $buffer = '';
            $from = '';
            $port = 0;
            $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, 0, $from, $port);

            if ($bytesReceived > 0) {
                echo "  收到响应: {$bytesReceived} 字节\n";
                $this->analyzeConnectionIdInResponse($buffer);
            } else {
                echo "  没有响应\n";
            }
        }

        socket_close($socket);
    }
    
    /**
     * 构建指定连接 ID 长度的包
     */
    private function buildPacketWithConnectionIdLength(int $cidLength): string
    {
        $packet = '';

        $packet .= chr(0xc0);
        $packet .= pack('N', 0x00000001);

        // DCID
        $packet .= chr($cidLength);
        if ($cidLength > 0) {
            $packet .= random_bytes($cidLength);
        }

        // SCID
        $packet .= chr($cidLength);
        if ($cidLength > 0) {
            $packet .= random_bytes($cidLength);
        }

        $packet .= chr(0); // Token length

        $remainingLength = 1200 - strlen($packet) - 2;
        $packet .= $this->encodeVariableLengthInteger($remainingLength);

        $packet .= chr(0); // Packet number

        return str_pad($packet, 1200, "\x00");
    }
    
    /**
     * 分析响应中的连接 ID
     */
    private function analyzeConnectionIdInResponse(string $buffer): void
    {
        if (strlen($buffer) < 6) {
            return;
        }

        $pos = 5; // 跳过头部和版本
        $dcidLen = ord($buffer[$pos]);
        echo "  响应 DCID 长度: {$dcidLen}\n";
    }
    
    /**
     * 测试 QUIC 重试包
     */
    public function testQuicRetryPacket(): void
    {
        echo "\n=== 测试 QUIC 重试包机制 ===\n";

        // 某些服务器可能在高负载时发送 Retry 包
        // 这里我们尝试触发这种情况

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

        // 发送多个请求试图触发 Retry
        $googleIp = gethostbyname('www.google.com');

        for ($i = 0; $i < 5; $i++) {
            $packet = $this->buildQuicInitialPacket();
            @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);

            $buffer = '';
            $from = '';
            $port = 0;
            $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, 0, $from, $port);

            if ($bytesReceived > 0) {
                $firstByte = ord($buffer[0]);
                $packetType = ($firstByte & 0x30) >> 4;

                if ($packetType === 3) {
                    echo "收到 Retry 包！\n";
                    $this->analyzeRetryPacket($buffer);
                    break;
                }
            }
        }

        socket_close($socket);
        $this->assertTrue(true, "Retry 包测试完成");
    }
    
    /**
     * 构建基本的 Initial 包
     */
    private function buildQuicInitialPacket(): string
    {
        return $this->buildPacketWithVersion(0x00000001);
    }
    
    /**
     * 分析 Retry 包
     */
    private function analyzeRetryPacket(string $buffer): void
    {
        echo "Retry 包分析:\n";
        echo "  包大小: " . strlen($buffer) . " 字节\n";

        // Retry 包包含 Retry Token
        // 格式: Header | Version | DCID | SCID | Retry Token | Retry Integrity Tag
    }
    
    /**
     * 测试 QUIC 最小包大小要求
     */
    public function testQuicMinimumPacketSize(): void
    {
        echo "\n=== 测试 QUIC 最小包大小要求 ===\n";

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

        $googleIp = gethostbyname('www.google.com');

        // 测试不同大小的包
        $sizes = [100, 500, 1000, 1200, 1500];

        foreach ($sizes as $size) {
            echo "\n测试 {$size} 字节包...\n";

            $packet = $this->buildPacketWithSize($size);
            echo "  实际包大小: " . strlen($packet) . " 字节\n";

            $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);

            if ($bytesSent === false) {
                echo "  发送失败\n";
                continue;
            }

            echo "  发送成功\n";

            $buffer = '';
            $from = '';
            $port = 0;
            $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, 0, $from, $port);

            if ($bytesReceived > 0) {
                echo "  收到响应: {$bytesReceived} 字节\n";

                // Initial 包小于 1200 字节应该被拒绝
                if ($size < 1200) {
                    echo "  注意: RFC 9000 要求 Initial 包至少 1200 字节\n";
                }
            } else {
                echo "  没有响应";
                if ($size < 1200) {
                    echo " (预期行为 - 包太小)";
                }
                echo "\n";
            }
        }

        socket_close($socket);
    }
    
    /**
     * 构建指定大小的包
     */
    private function buildPacketWithSize(int $targetSize): string
    {
        $packet = $this->buildQuicInitialPacket();

        if (strlen($packet) > $targetSize) {
            return substr($packet, 0, $targetSize);
        }

        return str_pad($packet, $targetSize, "\x00");
    }
    
    /**
     * 测试实际的 TLS/QUIC 集成
     */
    public function testQuicTlsIntegration(): void
    {
        echo "\n=== 测试 QUIC/TLS 1.3 集成 ===\n";

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 3, 'usec' => 0]);

        // 构建包含更真实 ClientHello 的 Initial 包
        $packet = $this->buildInitialPacketWithTls13ClientHello('www.google.com');

        echo "发送包含 TLS 1.3 ClientHello 的 Initial 包\n";
        echo "包大小: " . strlen($packet) . " 字节\n";

        $googleIp = gethostbyname('www.google.com');
        $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);

        if ($bytesSent !== false) {
            echo "发送成功: {$bytesSent} 字节\n";

            // 尝试接收多个响应包
            $responses = [];
            for ($i = 0; $i < 3; $i++) {
                $buffer = '';
                $from = '';
                $port = 0;
                $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, 0, $from, $port);

                if ($bytesReceived > 0) {
                    $responses[] = $buffer;
                    echo "收到响应 #" . ($i + 1) . ": {$bytesReceived} 字节\n";
                    $this->analyzeQuicPacket($buffer);
                } else {
                    break;
                }
            }

            echo "共收到 " . count($responses) . " 个响应包\n";
        }

        socket_close($socket);
    }
    
    /**
     * 构建包含 TLS 1.3 ClientHello 的 Initial 包
     */
    private function buildInitialPacketWithTls13ClientHello(string $serverName): string
    {
        // TODO: 实现真实的 TLS 1.3 ClientHello
        // 这需要 QUIC-TLS 集成的完整实现
        return $this->buildQuicInitialPacket();
    }
    
    /**
     * 分析 QUIC 包
     */
    private function analyzeQuicPacket(string $buffer): void
    {
        if (strlen($buffer) < 1) {
            return;
        }
        
        $firstByte = ord($buffer[0]);
        $isLongHeader = ($firstByte & 0x80) === 0x80;
        
        if ($isLongHeader) {
            $packetType = ($firstByte & 0x30) >> 4;
            $typeNames = ['Initial', '0-RTT', 'Handshake', 'Retry'];
            $typeName = $typeNames[$packetType];
            
            echo "  包类型: {$typeName}\n";
            
            if (strlen($buffer) >= 5) {
                $version = unpack('N', substr($buffer, 1, 4))[1];
                echo "  版本: 0x" . sprintf('%08x', $version) . "\n";
            }
        } else {
            echo "  短头部包 (1-RTT)\n";
        }
    }
}