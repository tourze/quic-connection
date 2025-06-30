<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * QUIC 初始包测试
 * 
 * 测试发送 QUIC Initial 包到真实服务器
 */
class QuicInitialPacketTest extends TestCase
{
    /**
     * 测试构建和发送 QUIC Initial 包
     */
    public function testBuildAndSendInitialPacket(): void
    {
        // 创建 UDP socket
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket, "应该能创建 UDP socket");
        
        // 设置为非阻塞模式
        socket_set_nonblock($socket);
        
        // 构建一个简单的 QUIC Initial 包
        // 这是一个极简的 QUIC v1 Initial 包结构
        $packet = $this->buildMinimalInitialPacket();
        
        // 发送到 Google 的 QUIC 服务器
        $sent = @socket_sendto($socket, $packet, strlen($packet), 0, 'www.google.com', 443);
        
        if ($sent === false) {
            $errorCode = socket_last_error($socket);
            $errorMsg = socket_strerror($errorCode);
            $this->markTestSkipped("无法发送包到 Google QUIC 服务器: {$errorMsg} (错误码: {$errorCode})");
        }
        
        $this->assertGreaterThan(0, $sent, "应该发送了一些字节");
        
        // 等待响应
        usleep(100000); // 100ms
        
        // 尝试接收响应
        $buffer = '';
        $from = '';
        $port = 0;
        $received = @socket_recvfrom($socket, $buffer, 65535, 0, $from, $port);
        
        socket_close($socket);
        
        if ($received > 0) {
            $this->assertGreaterThan(0, $received, "收到了响应");
            
            // 分析响应的第一个字节
            $firstByte = ord($buffer[0]);
            $isLongHeader = ($firstByte & 0x80) === 0x80;
            
            $this->assertTrue($isLongHeader, "响应应该是长头部包");
            
            // 检查包类型
            $packetType = ($firstByte & 0x30) >> 4;
            echo "收到的包类型: {$packetType}\n";
            
            // 如果是版本协商包 (没有固定的包类型位)
            if ($received >= 5) {
                $version = unpack('N', substr($buffer, 1, 4))[1];
                if ($version === 0) {
                    echo "收到版本协商包\n";
                    
                    // 解析支持的版本
                    $pos = 5; // 跳过第一个字节和版本字段
                    
                    // 跳过目标连接ID
                    $dcidLen = ord($buffer[$pos]);
                    $pos += 1 + $dcidLen;
                    
                    // 跳过源连接ID
                    $scidLen = ord($buffer[$pos]);
                    $pos += 1 + $scidLen;
                    
                    // 剩余的都是支持的版本
                    $versions = [];
                    while ($pos + 4 <= $received) {
                        $ver = unpack('N', substr($buffer, $pos, 4))[1];
                        $versions[] = sprintf('0x%08x', $ver);
                        $pos += 4;
                    }
                    
                    echo "服务器支持的版本: " . implode(', ', $versions) . "\n";
                    $this->assertNotEmpty($versions, "应该返回支持的版本列表");
                }
            }
        } else {
            $this->markTestSkipped("没有收到响应，可能是网络问题或防火墙阻止");
        }
    }
    
    /**
     * 构建一个最小的 QUIC Initial 包
     */
    private function buildMinimalInitialPacket(): string
    {
        // QUIC v1 Initial 包的最小结构
        $packet = '';
        
        // 头部字节: 1100 0000 (长头部, Initial包类型)
        $headerByte = 0xc0;
        
        // 使用一个不太可能支持的版本来触发版本协商
        $version = 0xbabababa;
        
        // 连接ID
        $dcidLen = 8;
        $dcid = random_bytes($dcidLen);
        $scidLen = 8;
        $scid = random_bytes($scidLen);
        
        // 构建包
        $packet .= chr($headerByte);
        $packet .= pack('N', $version);
        $packet .= chr($dcidLen) . $dcid;
        $packet .= chr($scidLen) . $scid;
        
        // Token 长度 (Initial包需要)
        $packet .= chr(0); // 没有 token
        
        // 包长度 (使用变长整数编码)
        // 这里使用一个简单的占位符
        $packet .= chr(0x40) . chr(0x00); // 表示长度为0的变长整数
        
        // 包编号 (加密的，这里用占位符)
        $packet .= chr(0x00);
        
        // 确保包至少有 1200 字节（QUIC 要求的最小 Initial 包大小）
        $packet = str_pad($packet, 1200, "\x00");
        
        return $packet;
    }
    
    /**
     * 测试与 Cloudflare QUIC 服务器的交互
     */
    public function testCloudflareQuicServer(): void
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($socket);
        
        socket_set_nonblock($socket);
        
        // 解析 cloudflare-quic.com 的 IP
        $ip = gethostbyname('cloudflare-quic.com');
        if ($ip === 'cloudflare-quic.com') {
            $this->markTestSkipped("无法解析 cloudflare-quic.com");
        }
        
        $packet = $this->buildMinimalInitialPacket();
        $sent = @socket_sendto($socket, $packet, strlen($packet), 0, $ip, 443);
        
        if ($sent === false) {
            $this->markTestSkipped("无法发送包到 Cloudflare QUIC 服务器");
        }
        
        $this->assertGreaterThan(0, $sent);
        
        // 等待响应
        usleep(200000); // 200ms
        
        $buffer = '';
        $from = '';
        $port = 0;
        $received = @socket_recvfrom($socket, $buffer, 65535, 0, $from, $port);
        
        socket_close($socket);
        
        if ($received > 0) {
            echo "从 Cloudflare 收到 {$received} 字节的响应\n";
            $this->assertGreaterThan(0, $received);
        } else {
            $this->markTestSkipped("没有从 Cloudflare 收到响应");
        }
    }
}