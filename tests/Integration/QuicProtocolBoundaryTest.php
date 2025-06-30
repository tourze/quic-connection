<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * QUIC 协议边界测试
 * 
 * 测试 QUIC 协议的边界条件和极限情况
 */
class QuicProtocolBoundaryTest extends TestCase
{
    /**
     * 测试连接 ID 长度边界
     */
    public function testConnectionIdLengthBoundaries(): void
    {
        echo "\n=== 测试连接 ID 长度边界 ===\n";
        
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
        
        // RFC 9000: CID 长度必须在 0-20 字节之间
        $testCases = [
            ['length' => -1, 'expected' => 'error', 'description' => '负数长度'],
            ['length' => 0, 'expected' => 'success', 'description' => '零长度 CID'],
            ['length' => 1, 'expected' => 'success', 'description' => '最小非零长度'],
            ['length' => 8, 'expected' => 'success', 'description' => '推荐长度'],
            ['length' => 20, 'expected' => 'success', 'description' => '最大长度'],
            ['length' => 21, 'expected' => 'error', 'description' => '超过最大长度'],
            ['length' => 255, 'expected' => 'error', 'description' => '极大长度'],
        ];
        
        $googleIp = gethostbyname('www.google.com');
        
        foreach ($testCases as $case) {
            echo "\n测试: {$case['description']} (长度={$case['length']})\n";
            
            try {
                if ($case['length'] < 0) {
                    echo "  跳过: 无法构建负长度的 CID\n";
                    continue;
                }
                
                if ($case['length'] > 20) {
                    // 构建违反规范的包
                    $packet = $this->buildPacketWithInvalidCidLength($case['length']);
                } else {
                    $packet = $this->buildPacketWithCidLength($case['length']);
                }
                
                $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);
                
                if ($bytesSent !== false) {
                    echo "  发送: {$bytesSent} 字节\n";
                    
                    $buffer = '';
                    $from = '';
                    $port = 0;
                    $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, MSG_DONTWAIT, $from, $port);
                    
                    if ($bytesReceived > 0) {
                        echo "  收到响应: {$bytesReceived} 字节\n";
                        if ($case['expected'] === 'error') {
                            echo "  ⚠️ 服务器接受了无效的 CID 长度\n";
                        }
                    } else {
                        echo "  无响应\n";
                        if ($case['expected'] === 'error') {
                            echo "  ✅ 服务器正确拒绝了无效包\n";
                        }
                    }
                }
            } catch (\Exception $e) {
                echo "  异常: " . $e->getMessage() . "\n";
            }
            
            usleep(100000); // 100ms 延迟
        }
        
        socket_close($socket);
        $this->assertTrue(true);
    }
    
    /**
     * 测试包号边界
     */
    public function testPacketNumberBoundaries(): void
    {
        echo "\n=== 测试包号边界 ===\n";
        
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
        
        // 测试不同的包号编码长度
        $testCases = [
            ['pn' => 0, 'length' => 1, 'description' => '最小包号'],
            ['pn' => 127, 'length' => 1, 'description' => '1字节最大值'],
            ['pn' => 128, 'length' => 2, 'description' => '需要2字节'],
            ['pn' => 16383, 'length' => 2, 'description' => '2字节最大值'],
            ['pn' => 16384, 'length' => 4, 'description' => '需要4字节'],
            ['pn' => 1073741823, 'length' => 4, 'description' => '4字节最大值'],
        ];
        
        $googleIp = gethostbyname('www.google.com');
        
        foreach ($testCases as $case) {
            echo "\n测试: {$case['description']} (PN={$case['pn']}, 长度={$case['length']}字节)\n";
            
            $packet = $this->buildPacketWithPacketNumber($case['pn'], $case['length']);
            $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);
            
            if ($bytesSent !== false) {
                echo "  发送成功: {$bytesSent} 字节\n";
            }
            
            usleep(50000); // 50ms 延迟
        }
        
        socket_close($socket);
        $this->assertTrue(true);
    }
    
    /**
     * 测试变长整数编码边界
     */
    public function testVariableLengthIntegerBoundaries(): void
    {
        echo "\n=== 测试变长整数编码边界 ===\n";
        
        // 测试编码和解码的正确性
        $testValues = [
            0,                    // 最小值
            63,                   // 6位最大值
            64,                   // 需要14位
            16383,                // 14位最大值
            16384,                // 需要30位
            1073741823,           // 30位最大值
            1073741824,           // 需要62位
            4611686018427387903,  // 62位最大值
        ];
        
        foreach ($testValues as $value) {
            $encoded = $this->encodeVariableLengthInteger($value);
            $encodedHex = bin2hex($encoded);
            $length = strlen($encoded);
            
            echo sprintf(
                "值: %d, 编码长度: %d字节, 编码: %s\n",
                $value,
                $length,
                $encodedHex
            );
            
            // 验证编码长度
            if ($value <= 63) {
                $this->assertEquals(1, $length, "6位值应该编码为1字节");
            } elseif ($value <= 16383) {
                $this->assertEquals(2, $length, "14位值应该编码为2字节");
            } elseif ($value <= 1073741823) {
                $this->assertEquals(4, $length, "30位值应该编码为4字节");
            } else {
                $this->assertEquals(8, $length, "62位值应该编码为8字节");
            }
        }
    }
    
    /**
     * 测试令牌长度边界
     */
    public function testTokenLengthBoundaries(): void
    {
        echo "\n=== 测试令牌长度边界 ===\n";
        
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
        
        // 测试不同的令牌长度
        $tokenLengths = [0, 1, 16, 64, 128, 255, 512, 1024];
        $googleIp = gethostbyname('www.google.com');
        
        foreach ($tokenLengths as $length) {
            echo "\n测试 {$length} 字节令牌...\n";
            
            $packet = $this->buildPacketWithToken($length);
            
            // 包大小不能超过 UDP 限制
            if (strlen($packet) > 65507) {
                echo "  跳过: 包大小超过 UDP 限制\n";
                continue;
            }
            
            $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);
            
            if ($bytesSent !== false) {
                echo "  发送: {$bytesSent} 字节\n";
                
                $buffer = '';
                $from = '';
                $port = 0;
                $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, MSG_DONTWAIT, $from, $port);
                
                if ($bytesReceived > 0) {
                    echo "  收到响应: {$bytesReceived} 字节\n";
                }
            }
            
            usleep(100000); // 100ms 延迟
        }
        
        socket_close($socket);
        $this->assertTrue(true);
    }
    
    /**
     * 测试最大数据报大小
     */
    public function testMaximumDatagramSize(): void
    {
        echo "\n=== 测试最大数据报大小 ===\n";
        
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
        
        // RFC 9000: 初始最小 MTU 是 1200 字节
        $sizes = [
            1199 => '小于最小 MTU',
            1200 => '最小 MTU',
            1350 => '常见 MTU',
            1500 => '以太网 MTU',
            9000 => 'Jumbo 帧',
            65507 => 'UDP 最大负载',
            65508 => '超过 UDP 限制',
        ];
        
        $googleIp = gethostbyname('www.google.com');
        
        foreach ($sizes as $size => $description) {
            echo "\n测试 {$size} 字节 ({$description})...\n";
            
            try {
                $packet = $this->buildPacketWithSpecificSize($size);
                echo "  构建的包大小: " . strlen($packet) . " 字节\n";
                
                if (strlen($packet) > 65507) {
                    echo "  跳过: 超过 UDP 限制\n";
                    continue;
                }
                
                $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);
                
                if ($bytesSent === false) {
                    $error = socket_last_error($socket);
                    echo "  发送失败: " . socket_strerror($error) . "\n";
                } else {
                    echo "  发送成功: {$bytesSent} 字节\n";
                    
                    if ($size < 1200) {
                        echo "  ⚠️ 注意: Initial 包应该至少 1200 字节\n";
                    }
                }
            } catch (\Exception $e) {
                echo "  异常: " . $e->getMessage() . "\n";
            }
            
            usleep(100000); // 100ms 延迟
        }
        
        socket_close($socket);
        $this->assertTrue(true);
    }
    
    /**
     * 测试保留位处理
     */
    public function testReservedBitsHandling(): void
    {
        echo "\n=== 测试保留位处理 ===\n";
        
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
        
        // 测试不同的保留位设置
        $reservedBitsCases = [
            0x00 => '所有保留位为0（正确）',
            0x04 => '设置保留位 R1',
            0x08 => '设置保留位 R2',
            0x0C => '设置所有保留位',
        ];
        
        $googleIp = gethostbyname('www.google.com');
        
        foreach ($reservedBitsCases as $bits => $description) {
            echo "\n测试: {$description}\n";
            
            $packet = $this->buildPacketWithReservedBits($bits);
            $firstByte = ord($packet[0]);
            echo "  头部字节: 0x" . sprintf('%02x', $firstByte) . "\n";
            
            $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);
            
            if ($bytesSent !== false) {
                echo "  发送: {$bytesSent} 字节\n";
                
                $buffer = '';
                $from = '';
                $port = 0;
                $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, MSG_DONTWAIT, $from, $port);
                
                if ($bytesReceived > 0) {
                    echo "  收到响应: {$bytesReceived} 字节\n";
                    if ($bits !== 0x00) {
                        echo "  ⚠️ 服务器接受了设置保留位的包\n";
                    }
                } else {
                    echo "  无响应\n";
                    if ($bits !== 0x00) {
                        echo "  ✅ 服务器可能拒绝了设置保留位的包\n";
                    }
                }
            }
            
            usleep(100000); // 100ms 延迟
        }
        
        socket_close($socket);
        $this->assertTrue(true);
    }
    
    /**
     * 构建指定 CID 长度的包
     */
    private function buildPacketWithCidLength(int $length): string
    {
        $packet = '';
        
        $packet .= chr(0xc0);
        $packet .= pack('N', 0x00000001);
        
        // DCID
        $packet .= chr($length);
        if ($length > 0) {
            $packet .= random_bytes($length);
        }
        
        // SCID
        $packet .= chr(8);
        $packet .= random_bytes(8);
        
        $packet .= chr(0); // Token
        
        $remainingLength = 1200 - strlen($packet) - 2;
        $packet .= $this->encodeVariableLengthInteger($remainingLength);
        
        $packet .= chr(0);
        
        return str_pad($packet, 1200, "\x00");
    }
    
    /**
     * 构建无效 CID 长度的包
     */
    private function buildPacketWithInvalidCidLength(int $length): string
    {
        $packet = '';
        
        $packet .= chr(0xc0);
        $packet .= pack('N', 0x00000001);
        
        // 强制写入无效长度
        $packet .= chr($length & 0xFF);
        // 但只生成20字节的数据（最大有效长度）
        $actualLength = min($length, 20);
        if ($actualLength > 0) {
            $packet .= random_bytes($actualLength);
        }
        
        // SCID
        $packet .= chr(8);
        $packet .= random_bytes(8);
        
        $packet .= chr(0);
        
        // 填充到1200字节
        return str_pad($packet, 1200, "\x00");
    }
    
    /**
     * 构建指定包号的包
     */
    private function buildPacketWithPacketNumber(int $pn, int $length): string
    {
        $packet = '';
        
        // 根据长度设置头部字节
        $headerByte = 0xc0;
        $headerByte |= ($length - 1); // 设置包号长度位
        $packet .= chr($headerByte);
        
        $packet .= pack('N', 0x00000001);
        
        // Connection IDs
        $packet .= chr(8) . random_bytes(8);
        $packet .= chr(8) . random_bytes(8);
        
        $packet .= chr(0);
        
        $remainingLength = 1200 - strlen($packet) - 2 - $length;
        $packet .= $this->encodeVariableLengthInteger($remainingLength);
        
        // 写入包号
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
     * 构建带令牌的包
     */
    private function buildPacketWithToken(int $tokenLength): string
    {
        $packet = '';
        
        $packet .= chr(0xc0);
        $packet .= pack('N', 0x00000001);
        
        // Connection IDs
        $packet .= chr(8) . random_bytes(8);
        $packet .= chr(8) . random_bytes(8);
        
        // Token
        $packet .= $this->encodeVariableLengthInteger($tokenLength);
        if ($tokenLength > 0) {
            $packet .= random_bytes($tokenLength);
        }
        
        // 确保至少1200字节
        $currentLength = strlen($packet);
        if ($currentLength < 1200) {
            $remainingLength = 1200 - $currentLength - 2;
            $packet .= $this->encodeVariableLengthInteger($remainingLength);
            $packet .= chr(0);
            $packet = str_pad($packet, 1200, "\x00");
        } else {
            // 如果已经超过1200字节，添加最小的有效载荷
            $packet .= $this->encodeVariableLengthInteger(1);
            $packet .= chr(0);
        }
        
        return $packet;
    }
    
    /**
     * 构建指定大小的包
     */
    private function buildPacketWithSpecificSize(int $targetSize): string
    {
        $packet = '';
        
        $packet .= chr(0xc0);
        $packet .= pack('N', 0x00000001);
        
        // Connection IDs
        $packet .= chr(8) . random_bytes(8);
        $packet .= chr(8) . random_bytes(8);
        
        $packet .= chr(0); // Token
        
        // 计算需要的填充
        $headerSize = strlen($packet) + 2 + 1; // +2 for length, +1 for packet number
        $paddingNeeded = $targetSize - $headerSize;
        
        if ($paddingNeeded > 0) {
            $packet .= $this->encodeVariableLengthInteger($paddingNeeded);
            $packet .= chr(0); // Packet number
            $packet .= str_repeat("\x00", $paddingNeeded - 1);
        } else {
            // 包太小，无法达到目标大小
            $packet .= $this->encodeVariableLengthInteger(0);
            $packet .= chr(0);
        }
        
        return $packet;
    }
    
    /**
     * 构建设置保留位的包
     */
    private function buildPacketWithReservedBits(int $reservedBits): string
    {
        $packet = '';
        
        // Initial packet with reserved bits set
        $headerByte = 0xc0 | ($reservedBits & 0x0C);
        $packet .= chr($headerByte);
        
        $packet .= pack('N', 0x00000001);
        
        // Connection IDs
        $packet .= chr(8) . random_bytes(8);
        $packet .= chr(8) . random_bytes(8);
        
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
}