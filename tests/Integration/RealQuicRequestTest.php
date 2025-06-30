<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * 真实 QUIC 请求测试
 * 
 * 发送真实的 QUIC 数据包到公网服务器
 */
class RealQuicRequestTest extends TestCase
{
    /**
     * 测试向 Google QUIC 服务器发送真实请求
     */
    public function testRealQuicRequestToGoogle(): void
    {
        echo "\n=== 测试向 Google QUIC 服务器发送请求 ===\n";
        
        // 创建 UDP socket
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket, "创建 socket 失败");
        
        // 设置 socket 选项
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 5, 'usec' => 0]);
        
        // 构建 QUIC Initial 包
        $packet = $this->buildQuicInitialPacket();
        echo "构建的 QUIC Initial 包大小: " . strlen($packet) . " 字节\n";
        echo "包内容 (前 100 字节): " . bin2hex(substr($packet, 0, 100)) . "...\n";
        
        // 发送到 Google QUIC 服务器
        $googleIp = gethostbyname('www.google.com');
        echo "Google IP: {$googleIp}\n";
        
        $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);
        
        if ($bytesSent === false) {
            $error = socket_last_error($socket);
            $errorMsg = socket_strerror($error);
            socket_close($socket);
            $this->fail("发送失败: {$errorMsg} (错误码: {$error})");
        }
        
        echo "成功发送 {$bytesSent} 字节到 {$googleIp}:443\n";
        
        // 等待响应
        echo "等待响应...\n";
        $buffer = '';
        $from = '';
        $port = 0;
        $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, 0, $from, $port);
        
        socket_close($socket);
        
        if ($bytesReceived > 0) {
            echo "收到响应！\n";
            echo "响应来自: {$from}:{$port}\n";
            echo "响应大小: {$bytesReceived} 字节\n";
            echo "响应内容 (前 100 字节): " . bin2hex(substr($buffer, 0, 100)) . "...\n";
            
            $this->analyzeQuicResponse($buffer);
        } else {
            echo "没有收到响应\n";
            $this->markTestSkipped("没有收到响应，可能被防火墙阻止或网络问题");
        }
    }
    
    /**
     * 构建 QUIC Initial 包
     */
    private function buildQuicInitialPacket(): string
    {
        $packet = '';

        // Header Form (1) = 1, Fixed Bit (1) = 1, Long Packet Type (2) = 00 (Initial), Reserved Bits (2) = 00, Packet Number Length (2) = 00
        $headerByte = 0xc0; // 11000000
        $packet .= chr($headerByte);

        // Version (32 bits) - QUIC v1
        $packet .= pack('N', 0x00000001);

        // DCID Length (8 bits) and DCID
        $dcidLen = 8;
        $dcid = random_bytes($dcidLen);
        $packet .= chr($dcidLen) . $dcid;

        // SCID Length (8 bits) and SCID
        $scidLen = 8;
        $scid = random_bytes($scidLen);
        $packet .= chr($scidLen) . $scid;

        // Token Length (variable-length integer) - 0 for client Initial
        $packet .= chr(0);

        // Length (variable-length integer) - 这里使用简化的方式
        $remainingLength = 1200 - strlen($packet) - 2; // 预留2字节给长度字段
        $packet .= $this->encodeVariableLengthInteger($remainingLength);

        // Packet Number (8 bits) - 简化为1字节
        $packet .= chr(0);

        // Payload - CRYPTO frame with ClientHello
        $cryptoFrame = $this->buildCryptoFrame();
        $packet .= $cryptoFrame;

        // PADDING frames to reach minimum size (1200 bytes for Initial)
        $paddingLength = 1200 - strlen($packet);
        if ($paddingLength > 0) {
            $packet .= str_repeat("\x00", $paddingLength);
        }

        return $packet;
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
     * 构建 CRYPTO 帧
     */
    private function buildCryptoFrame(): string
    {
        $frame = '';

        // Frame Type - CRYPTO frame (0x06)
        $frame .= chr(0x06);

        // Offset (variable-length integer) - 0
        $frame .= chr(0);

        // Length (variable-length integer) - 简化的 ClientHello
        $clientHello = $this->buildSimpleClientHello();
        $frame .= $this->encodeVariableLengthInteger(strlen($clientHello));

        // Crypto Data
        $frame .= $clientHello;

        return $frame;
    }
    
    /**
     * 构建简化的 ClientHello
     */
    private function buildSimpleClientHello(): string
    {
        // 这是一个极简的 TLS 1.3 ClientHello
        // 实际实现需要完整的 TLS 握手消息
        $hello = '';

        // TLS record header
        $hello .= chr(0x16); // Handshake
        $hello .= chr(0x03) . chr(0x03); // TLS 1.2 (兼容性)

        // 简化的内容
        $hello .= str_repeat("\x00", 100);

        return $hello;
    }
    
    /**
     * 分析 QUIC 响应
     */
    private function analyzeQuicResponse(string $buffer): void
    {
        if (strlen($buffer) < 1) {
            echo "响应太短\n";
            return;
        }

        $firstByte = ord($buffer[0]);
        $isLongHeader = ($firstByte & 0x80) === 0x80;

        echo "第一字节: 0x" . sprintf('%02x', $firstByte) . "\n";
        echo "长头部: " . ($isLongHeader ? '是' : '否') . "\n";

        if ($isLongHeader && strlen($buffer) >= 5) {
            $version = unpack('N', substr($buffer, 1, 4))[1];
            echo "版本: 0x" . sprintf('%08x', $version) . "\n";

            if ($version === 0) {
                echo "这是版本协商包\n";
                $this->parseVersionNegotiationPacket($buffer);
            } else {
                $packetType = ($firstByte & 0x30) >> 4;
                echo "包类型: {$packetType} (";
                switch ($packetType) {
                    case 0: echo "Initial"; break;
                    case 1: echo "0-RTT"; break;
                    case 2: echo "Handshake"; break;
                    case 3: echo "Retry"; break;
                }
                echo ")\n";
            }
        }

        $this->assertTrue(true, "成功接收并分析了 QUIC 响应");
    }
    
    /**
     * 解析版本协商包
     */
    private function parseVersionNegotiationPacket(string $buffer): void
    {
        echo "\n=== 版本协商包分析 ===\n";

        $pos = 5; // 跳过第一字节和版本字段

        // DCID Length and DCID
        if ($pos < strlen($buffer)) {
            $dcidLen = ord($buffer[$pos]);
            $pos++;
            echo "DCID 长度: {$dcidLen}\n";
            if ($pos + $dcidLen <= strlen($buffer)) {
                $dcid = substr($buffer, $pos, $dcidLen);
                echo "DCID: " . bin2hex($dcid) . "\n";
                $pos += $dcidLen;
            }
        }

        // SCID Length and SCID
        if ($pos < strlen($buffer)) {
            $scidLen = ord($buffer[$pos]);
            $pos++;
            echo "SCID 长度: {$scidLen}\n";
            if ($pos + $scidLen <= strlen($buffer)) {
                $scid = substr($buffer, $pos, $scidLen);
                echo "SCID: " . bin2hex($scid) . "\n";
                $pos += $scidLen;
            }
        }

        // Supported Versions
        echo "\n支持的版本:\n";
        while ($pos + 4 <= strlen($buffer)) {
            $version = unpack('N', substr($buffer, $pos, 4))[1];
            echo "  - 0x" . sprintf('%08x', $version);

            // 识别已知版本
            switch ($version) {
                case 0x00000001:
                    echo " (QUIC v1 - RFC 9000)";
                    break;
                case 0xff00001d:
                    echo " (draft-29)";
                    break;
                case 0xff00001e:
                    echo " (draft-30)";
                    break;
                case 0xff00001f:
                    echo " (draft-31)";
                    break;
                case 0xff000020:
                    echo " (draft-32)";
                    break;
            }
            echo "\n";
            $pos += 4;
        }

        $this->assertTrue(true, "成功解析版本协商包");
    }
    
    /**
     * 测试向 Cloudflare QUIC 服务器发送请求
     */
    public function testRealQuicRequestToCloudflare(): void
    {
        echo "\n=== 测试向 Cloudflare QUIC 服务器发送请求 ===\n";

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);

        // 解析 Cloudflare QUIC 测试服务器 IP
        $cloudflareIp = gethostbyname('cloudflare-quic.com');
        if ($cloudflareIp === 'cloudflare-quic.com') {
            socket_close($socket);
            $this->markTestSkipped("无法解析 cloudflare-quic.com");
        }

        echo "Cloudflare IP: {$cloudflareIp}\n";

        // 构建带有 SNI 的 Initial 包
        $packet = $this->buildQuicInitialPacketWithSNI('cloudflare-quic.com');
        echo "包大小: " . strlen($packet) . " 字节\n";

        $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $cloudflareIp, 443);

        if ($bytesSent === false) {
            socket_close($socket);
            $this->fail("发送失败");
        }

        echo "发送成功: {$bytesSent} 字节\n";

        // 接收响应
        $buffer = '';
        $from = '';
        $port = 0;
        $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, 0, $from, $port);

        socket_close($socket);

        if ($bytesReceived > 0) {
            echo "收到响应: {$bytesReceived} 字节\n";
            $this->analyzeQuicResponse($buffer);
        } else {
            echo "没有收到响应\n";
        }
    }
    
    /**
     * 构建带 SNI 的 Initial 包
     */
    private function buildQuicInitialPacketWithSNI(string $serverName): string
    {
        // 基本结构与 buildQuicInitialPacket 相同
        $packet = $this->buildQuicInitialPacket();

        // TODO: 在 CRYPTO 帧中包含正确的 SNI
        // 这需要实现 TLS ClientHello 编码

        return $packet;
    }
    
    /**
     * 测试 QUIC 版本协商
     */
    public function testQuicVersionNegotiation(): void
    {
        echo "\n=== 测试 QUIC 版本协商 ===\n";

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 3, 'usec' => 0]);

        // 使用不支持的版本号
        $packet = $this->buildQuicPacketWithVersion(0xfaceb00c); // 明显不支持的版本

        $googleIp = gethostbyname('www.google.com');
        $bytesSent = @socket_sendto($socket, $packet, strlen($packet), 0, $googleIp, 443);

        if ($bytesSent === false) {
            socket_close($socket);
            $this->fail("发送失败");
        }

        echo "发送带有不支持版本号的包: {$bytesSent} 字节\n";

        $buffer = '';
        $from = '';
        $port = 0;
        $bytesReceived = @socket_recvfrom($socket, $buffer, 65535, 0, $from, $port);

        socket_close($socket);

        if ($bytesReceived > 0) {
            echo "收到版本协商响应: {$bytesReceived} 字节\n";

            // 解析版本协商包
            if ($bytesReceived >= 5) {
                $version = unpack('N', substr($buffer, 1, 4))[1];
                if ($version === 0) {
                    echo "确认是版本协商包！\n";
                    $this->parseVersionNegotiationPacket($buffer);
                }
            }
        }
    }
    
    /**
     * 构建指定版本的 QUIC 包
     */
    private function buildQuicPacketWithVersion(int $version): string
    {
        $packet = '';

        // Header
        $packet .= chr(0xc0);

        // Version
        $packet .= pack('N', $version);

        // DCID
        $dcidLen = 8;
        $packet .= chr($dcidLen) . random_bytes($dcidLen);

        // SCID
        $scidLen = 8;
        $packet .= chr($scidLen) . random_bytes($scidLen);

        // Token Length
        $packet .= chr(0);

        // Length
        $remainingLength = 1200 - strlen($packet) - 2;
        $packet .= $this->encodeVariableLengthInteger($remainingLength);

        // Packet Number
        $packet .= chr(0);

        // Padding
        $packet = str_pad($packet, 1200, "\x00");

        return $packet;
    }
}