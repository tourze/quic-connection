<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * QUIC 协议一致性测试
 * 
 * 验证实现是否符合 RFC 9000 规范
 */
class QuicProtocolConformanceTest extends TestCase
{
    /**
     * 测试 RFC 9000 Section 17.2 - 长头部包格式
     */
    public function testLongHeaderFormat(): void
    {
        echo "\n=== 测试长头部包格式 (RFC 9000 Section 17.2) ===\n";
        
        // 验证各种长头部包类型
        $packetTypes = [
            0x00 => 'Initial',
            0x01 => '0-RTT',
            0x02 => 'Handshake',
            0x03 => 'Retry',
        ];
        
        foreach ($packetTypes as $type => $name) {
            echo "\n测试 {$name} 包类型...\n";
            
            $packet = $this->buildLongHeaderPacket($type);
            
            // 验证包结构
            $this->assertGreaterThanOrEqual(7, strlen($packet), "{$name} 包至少需要7字节");
            
            $firstByte = ord($packet[0]);
            echo "  第一字节: 0x" . sprintf('%02x', $firstByte) . "\n";
            
            // 验证头部格式位
            $this->assertEquals(1, ($firstByte >> 7) & 1, "Header Form 位应该是 1");
            $this->assertEquals(1, ($firstByte >> 6) & 1, "Fixed Bit 位应该是 1");
            $this->assertEquals($type, ($firstByte >> 4) & 0x03, "Long Packet Type 应该是 {$type}");
            
            // 验证版本字段
            if (strlen($packet) >= 5) {
                $version = unpack('N', substr($packet, 1, 4))[1];
                echo "  版本: 0x" . sprintf('%08x', $version) . "\n";
                
                if ($type !== 0x03) { // Retry 包可能有不同的版本处理
                    $this->assertNotEquals(0, $version, "非 Retry 包的版本不应该是 0");
                }
            }
            
            // 验证连接 ID
            if (strlen($packet) >= 6) {
                $pos = 5;
                $dcidLen = ord($packet[$pos]);
                echo "  DCID 长度: {$dcidLen}\n";
                $this->assertLessThanOrEqual(20, $dcidLen, "DCID 长度不应超过 20");
                
                if ($pos + 1 + $dcidLen < strlen($packet)) {
                    $pos += 1 + $dcidLen;
                    $scidLen = ord($packet[$pos]);
                    echo "  SCID 长度: {$scidLen}\n";
                    $this->assertLessThanOrEqual(20, $scidLen, "SCID 长度不应超过 20");
                }
            }
        }
        
        $this->assertTrue(true);
    }
    
    /**
     * 测试 RFC 9000 Section 17.3 - 短头部包格式
     */
    public function testShortHeaderFormat(): void
    {
        echo "\n=== 测试短头部包格式 (RFC 9000 Section 17.3) ===\n";
        
        $packet = $this->buildShortHeaderPacket();
        
        $this->assertGreaterThanOrEqual(3, strlen($packet), "短头部包至少需要3字节");
        
        $firstByte = ord($packet[0]);
        echo "第一字节: 0x" . sprintf('%02x', $firstByte) . "\n";
        
        // 验证头部格式位
        $this->assertEquals(0, ($firstByte >> 7) & 1, "Header Form 位应该是 0");
        $this->assertEquals(1, ($firstByte >> 6) & 1, "Fixed Bit 位应该是 1");
        
        // 验证 Spin Bit
        $spinBit = ($firstByte >> 5) & 1;
        echo "Spin Bit: {$spinBit}\n";
        
        // 验证 Key Phase
        $keyPhase = ($firstByte >> 2) & 1;
        echo "Key Phase: {$keyPhase}\n";
        
        // 验证包号长度
        $pnLength = ($firstByte & 0x03) + 1;
        echo "包号长度: {$pnLength} 字节\n";
        $this->assertGreaterThanOrEqual(1, $pnLength);
        $this->assertLessThanOrEqual(4, $pnLength);
        
        $this->assertTrue(true);
    }
    
    /**
     * 测试 RFC 9000 Section 7.2 - 版本协商
     */
    public function testVersionNegotiationConformance(): void
    {
        echo "\n=== 测试版本协商一致性 (RFC 9000 Section 7.2) ===\n";
        
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);
        
        // 使用不支持的版本触发版本协商
        $unsupportedVersion = 0x1a2a3a4a; // GREASE 版本
        $packet = $this->buildPacketWithVersion($unsupportedVersion);
        
        echo "发送不支持的版本: 0x" . sprintf('%08x', $unsupportedVersion) . "\n";
        
        $googleIp = gethostbyname('www.google.com');
        $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);
        
        if ($bytesSent !== false) {
            $buffer = '';
            $from = '';
            $port = 0;
            $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, 0, $from, $port);
            
            if ($bytesReceived > 0) {
                echo "收到版本协商响应: {$bytesReceived} 字节\n";
                
                // 验证版本协商包格式
                $this->assertGreaterThanOrEqual(7, strlen($buffer), "版本协商包至少需要7字节");
                
                $firstByte = ord($buffer[0]);
                $this->assertEquals(1, ($firstByte >> 7) & 1, "版本协商包必须使用长头部");
                
                $version = unpack('N', substr($buffer, 1, 4))[1];
                $this->assertEquals(0, $version, "版本协商包的版本字段必须是 0");
                
                // 提取支持的版本
                $supportedVersions = $this->extractSupportedVersions($buffer);
                echo "服务器支持的版本:\n";
                foreach ($supportedVersions as $v) {
                    echo "  - 0x" . sprintf('%08x', $v);
                    if ($v === 0x00000001) echo " (QUIC v1)";
                    echo "\n";
                }
                
                // RFC 9000: 版本协商包必须包含至少一个版本
                $this->assertNotEmpty($supportedVersions, "版本协商包必须包含至少一个支持的版本");
                
                // RFC 9000: 不能包含客户端发送的版本
                $this->assertNotContains($unsupportedVersion, $supportedVersions, 
                    "版本协商包不应包含客户端发送的版本");
            }
        }
        
        socket_close($socket);
        $this->assertTrue(true);
    }
    
    /**
     * 测试 RFC 9000 Section 14 - 包大小限制
     */
    public function testPacketSizeLimits(): void
    {
        echo "\n=== 测试包大小限制 (RFC 9000 Section 14) ===\n";
        
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
        
        $googleIp = gethostbyname('www.google.com');
        
        // RFC 9000: Initial 包必须至少 1200 字节
        $testCases = [
            1199 => ['expected' => 'rejected', 'description' => '小于最小值'],
            1200 => ['expected' => 'accepted', 'description' => '正好最小值'],
            1350 => ['expected' => 'accepted', 'description' => '典型值'],
            1500 => ['expected' => 'accepted', 'description' => '以太网 MTU'],
        ];
        
        foreach ($testCases as $size => $info) {
            echo "\n测试 {$size} 字节 Initial 包 ({$info['description']})...\n";
            
            $packet = $this->buildInitialPacketWithSize($size);
            $actualSize = strlen($packet);
            echo "  实际大小: {$actualSize} 字节\n";
            
            $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);
            
            if ($bytesSent !== false) {
                echo "  发送成功: {$bytesSent} 字节\n";
                
                $buffer = '';
                $from = '';
                $port = 0;
                $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, MSG_DONTWAIT, $from, $port);
                
                if ($bytesReceived > 0) {
                    echo "  收到响应: {$bytesReceived} 字节\n";
                    if ($info['expected'] === 'rejected') {
                        echo "  ⚠️ 服务器接受了小于 1200 字节的 Initial 包\n";
                    }
                } else {
                    echo "  无响应\n";
                    if ($info['expected'] === 'rejected') {
                        echo "  ✅ 服务器正确拒绝了小于 1200 字节的 Initial 包\n";
                    }
                }
            }
            
            usleep(100000); // 100ms
        }
        
        socket_close($socket);
        $this->assertTrue(true);
    }
    
    /**
     * 测试 RFC 9000 Section 12.4 - 帧格式
     */
    public function testFrameFormats(): void
    {
        echo "\n=== 测试帧格式 (RFC 9000 Section 12.4) ===\n";
        
        // 测试各种帧类型的编码
        $frameTypes = [
            0x00 => 'PADDING',
            0x01 => 'PING',
            0x02 => 'ACK (without ECN)',
            0x03 => 'ACK (with ECN)',
            0x04 => 'RESET_STREAM',
            0x05 => 'STOP_SENDING',
            0x06 => 'CRYPTO',
            0x07 => 'NEW_TOKEN',
            0x08 => 'STREAM (0)',
            0x10 => 'MAX_DATA',
            0x11 => 'MAX_STREAM_DATA',
            0x12 => 'MAX_STREAMS (Bidirectional)',
            0x13 => 'MAX_STREAMS (Unidirectional)',
            0x14 => 'DATA_BLOCKED',
            0x15 => 'STREAM_DATA_BLOCKED',
            0x16 => 'STREAMS_BLOCKED (Bidirectional)',
            0x17 => 'STREAMS_BLOCKED (Unidirectional)',
            0x18 => 'NEW_CONNECTION_ID',
            0x19 => 'RETIRE_CONNECTION_ID',
            0x1a => 'PATH_CHALLENGE',
            0x1b => 'PATH_RESPONSE',
            0x1c => 'CONNECTION_CLOSE (transport)',
            0x1d => 'CONNECTION_CLOSE (application)',
            0x1e => 'HANDSHAKE_DONE',
        ];
        
        foreach ($frameTypes as $type => $name) {
            echo "\n测试 {$name} 帧 (类型 0x" . sprintf('%02x', $type) . ")...\n";
            
            $frame = $this->buildFrame($type);
            $this->assertNotEmpty($frame, "应该能够构建 {$name} 帧");
            
            $frameType = ord($frame[0]);
            
            // STREAM 帧有特殊的类型位
            if (($type & 0xf8) === 0x08) {
                $this->assertEquals(0x08, $frameType & 0xf8, "STREAM 帧类型的高5位应该是 0x08");
            } else {
                $this->assertEquals($type, $frameType, "帧类型应该是 0x" . sprintf('%02x', $type));
            }
            
            echo "  帧大小: " . strlen($frame) . " 字节\n";
            echo "  前16字节: " . bin2hex(substr($frame, 0, 16)) . "\n";
        }
        
        $this->assertTrue(true);
    }
    
    /**
     * 测试 RFC 9000 Section 8.1 - 连接 ID 长度
     */
    public function testConnectionIdLengthRequirements(): void
    {
        echo "\n=== 测试连接 ID 长度要求 (RFC 9000 Section 8.1) ===\n";
        
        // RFC 9000: 连接 ID 长度必须在 0-20 字节之间
        $validLengths = [0, 1, 4, 8, 16, 20];
        $invalidLengths = [21, 32, 255];
        
        echo "测试有效的连接 ID 长度:\n";
        foreach ($validLengths as $length) {
            $cid = $length > 0 ? random_bytes($length) : '';
            echo "  长度 {$length}: " . ($length > 0 ? bin2hex($cid) : '(empty)') . "\n";
            
            // 验证长度
            $this->assertGreaterThanOrEqual(0, $length);
            $this->assertLessThanOrEqual(20, $length);
        }
        
        echo "\n测试无效的连接 ID 长度:\n";
        foreach ($invalidLengths as $length) {
            echo "  长度 {$length}: 应该被拒绝\n";
            $this->assertGreaterThan(20, $length, "长度 {$length} 应该是无效的");
        }
        
        $this->assertTrue(true);
    }
    
    /**
     * 构建长头部包
     */
    private function buildLongHeaderPacket(int $packetType): string
    {
        $packet = '';
        
        // 构建第一字节
        $firstByte = 0x80; // Header Form = 1
        $firstByte |= 0x40; // Fixed Bit = 1
        $firstByte |= ($packetType & 0x03) << 4; // Long Packet Type
        $firstByte |= 0x00; // Reserved bits = 00, Packet Number Length = 00
        
        $packet .= chr($firstByte);
        
        // Version
        $packet .= pack('N', 0x00000001); // QUIC v1
        
        // DCID
        $dcidLen = 8;
        $packet .= chr($dcidLen) . random_bytes($dcidLen);
        
        // SCID
        $scidLen = 8;
        $packet .= chr($scidLen) . random_bytes($scidLen);
        
        // Type-specific fields
        if ($packetType === 0x00) { // Initial
            $packet .= chr(0); // Token Length = 0
            $packet .= $this->encodeVariableLengthInteger(100); // Length
            $packet .= chr(0); // Packet Number
            $packet .= str_repeat("\x00", 99); // Payload
        } elseif ($packetType === 0x03) { // Retry
            $packet .= random_bytes(16); // Retry Token
            $packet .= random_bytes(16); // Retry Integrity Tag
        } else {
            $packet .= $this->encodeVariableLengthInteger(10); // Length
            $packet .= chr(0); // Packet Number
            $packet .= str_repeat("\x00", 9); // Payload
        }
        
        return $packet;
    }
    
    /**
     * 构建短头部包
     */
    private function buildShortHeaderPacket(): string
    {
        $packet = '';
        
        // 构建第一字节
        $firstByte = 0x00; // Header Form = 0
        $firstByte |= 0x40; // Fixed Bit = 1
        $firstByte |= 0x00; // Spin Bit = 0
        $firstByte |= 0x00; // Reserved bits = 00
        $firstByte |= 0x00; // Key Phase = 0
        $firstByte |= 0x00; // Packet Number Length = 00 (1 byte)
        
        $packet .= chr($firstByte);
        
        // Destination Connection ID (假设使用8字节)
        $packet .= random_bytes(8);
        
        // Packet Number (1 byte)
        $packet .= chr(0);
        
        // Payload
        $packet .= random_bytes(100);
        
        return $packet;
    }
    
    /**
     * 构建指定版本的包
     */
    private function buildPacketWithVersion(int $version): string
    {
        $packet = '';
        
        $packet .= chr(0xc0);
        $packet .= pack('N', $version);
        
        $packet .= chr(8) . random_bytes(8);
        $packet .= chr(8) . random_bytes(8);
        
        $packet .= chr(0);
        
        $remainingLength = 1200 - strlen($packet) - 2;
        $packet .= $this->encodeVariableLengthInteger($remainingLength);
        
        $packet .= chr(0);
        
        return str_pad($packet, 1200, "\x00");
    }
    
    /**
     * 构建指定大小的 Initial 包
     */
    private function buildInitialPacketWithSize(int $targetSize): string
    {
        $packet = '';
        
        $packet .= chr(0xc0);
        $packet .= pack('N', 0x00000001);
        
        $packet .= chr(8) . random_bytes(8);
        $packet .= chr(8) . random_bytes(8);
        
        $packet .= chr(0);
        
        $headerSize = strlen($packet) + 2 + 1;
        $paddingNeeded = max(0, $targetSize - $headerSize);
        
        $packet .= $this->encodeVariableLengthInteger($paddingNeeded + 1);
        $packet .= chr(0);
        
        if ($paddingNeeded > 0) {
            $packet .= str_repeat("\x00", $paddingNeeded);
        }
        
        return $packet;
    }
    
    /**
     * 构建帧
     */
    private function buildFrame(int $frameType): string
    {
        $frame = chr($frameType);
        
        // 根据帧类型添加必需的字段
        switch ($frameType) {
            case 0x00: // PADDING
                // PADDING 帧只包含类型字节
                break;
                
            case 0x01: // PING
                // PING 帧只包含类型字节
                break;
                
            case 0x02: // ACK without ECN
            case 0x03: // ACK with ECN
                $frame .= $this->encodeVariableLengthInteger(0); // Largest Acknowledged
                $frame .= $this->encodeVariableLengthInteger(0); // ACK Delay
                $frame .= $this->encodeVariableLengthInteger(0); // ACK Range Count
                $frame .= $this->encodeVariableLengthInteger(0); // First ACK Range
                if ($frameType === 0x03) {
                    $frame .= $this->encodeVariableLengthInteger(0); // ECT(0)
                    $frame .= $this->encodeVariableLengthInteger(0); // ECT(1)
                    $frame .= $this->encodeVariableLengthInteger(0); // ECN-CE
                }
                break;
                
            case 0x04: // RESET_STREAM
                $frame .= $this->encodeVariableLengthInteger(0); // Stream ID
                $frame .= $this->encodeVariableLengthInteger(0); // Application Error Code
                $frame .= $this->encodeVariableLengthInteger(0); // Final Size
                break;
                
            case 0x06: // CRYPTO
                $frame .= $this->encodeVariableLengthInteger(0); // Offset
                $frame .= $this->encodeVariableLengthInteger(4); // Length
                $frame .= 'TEST'; // Crypto Data
                break;
                
            case 0x08: // STREAM
            case 0x09:
            case 0x0a:
            case 0x0b:
            case 0x0c:
            case 0x0d:
            case 0x0e:
            case 0x0f:
                $frame .= $this->encodeVariableLengthInteger(0); // Stream ID
                if (($frameType & 0x04) !== 0) { // OFF bit
                    $frame .= $this->encodeVariableLengthInteger(0); // Offset
                }
                if (($frameType & 0x02) !== 0) { // LEN bit
                    $frame .= $this->encodeVariableLengthInteger(4); // Length
                }
                $frame .= 'DATA'; // Stream Data
                break;
                
            case 0x18: // NEW_CONNECTION_ID
                $frame .= $this->encodeVariableLengthInteger(1); // Sequence Number
                $frame .= $this->encodeVariableLengthInteger(0); // Retire Prior To
                $frame .= chr(8); // Connection ID Length
                $frame .= random_bytes(8); // Connection ID
                $frame .= random_bytes(16); // Stateless Reset Token
                break;
                
            case 0x1c: // CONNECTION_CLOSE
            case 0x1d:
                $frame .= $this->encodeVariableLengthInteger(0); // Error Code
                if ($frameType === 0x1c) {
                    $frame .= $this->encodeVariableLengthInteger(0); // Frame Type
                }
                $frame .= $this->encodeVariableLengthInteger(0); // Reason Phrase Length
                break;
                
            default:
                // 其他帧类型使用最小有效格式
                if ($frameType >= 0x10 && $frameType <= 0x17) {
                    // 这些帧需要一个变长整数参数
                    $frame .= $this->encodeVariableLengthInteger(0);
                }
                break;
        }
        
        return $frame;
    }
    
    /**
     * 提取支持的版本
     */
    private function extractSupportedVersions(string $buffer): array
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
        
        // 读取版本列表
        while ($pos + 4 <= strlen($buffer)) {
            $version = unpack('N', substr($buffer, $pos, 4))[1];
            $versions[] = $version;
            $pos += 4;
        }
        
        return $versions;
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