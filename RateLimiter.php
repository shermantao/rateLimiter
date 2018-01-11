<?php
/**
 * Created by PhpStorm.
 * User: sherman
 * Date: 2018/1/10
 * Time: 20:30
 */


/**
 * 简易令牌桶算法
 * Class RateLimiter
 */
class RateLimiter
{

    public static function tokenBucket()
    {
        $consumes = false;

        $redis = new Redis();
        $result = $redis->connect('127.0.0.1', 6379);

        //向令牌桶中添加令牌的速率(个/秒)
        $rate = 10;
        //令牌桶的最大容量
        $defaultToken = 100;
        //redis key 的过期时间
        $timeOut = 60*30;

        $redisTokenKey = 'a:token:%s';
        $redisTimeKey = 'a:time:%s';

        $ipKey = ip2long(self::getUserIP());
        $rateTokenKey = sprintf($redisTokenKey, $ipKey);
        $rateTimeKey = sprintf($redisTimeKey, $ipKey);

        while (true) {
            try {
                $redis->watch($rateTokenKey);
                $redis->watch($rateTimeKey);

                $now = time();

                $currentToken = 0;
                //获取令牌桶中剩余令牌
                $oldToken = $redis->get($rateTokenKey);
                if (empty($oldToken)) {
                    $currentToken = $defaultToken;
                } else {
                    $oldRateTime = $redis->get($rateTimeKey);

                    //通过时间戳计算这段时间内应该添加多少令牌，如果桶满，令牌数取桶满数。
                    $currentToken = (float)$oldToken + min(
                        ($now - (float)$oldRateTime) * $rate,
                        $defaultToken - (float)$oldToken);
                }

                //判断剩余令牌是否足够
                if ($currentToken >= 1) {
                    $currentToken -= 1;
                    $consumes = true;
                } else {
                    $consumes = false;
                }

                //以下动作为更新 redis 中key的值，并跳出循环返回结果
                $multi = $redis->multi();
                $multi->set($rateTokenKey, $currentToken);
                $multi->expire($rateTokenKey, $timeOut);
                $multi->set($rateTimeKey, $now);
                $multi->expire($rateTimeKey, $timeOut);
                //执行事务成功，跳出循环
                if (empty($multi->exec())) {
                    break;
                }
            } catch (Exception $e) {
                continue;
            }
        }

        return $consumes;
    }

    /**
     * 获取客户端真实IP
     * @param bool $onlyOne 是否只返回单独一个的IP,因为有情况获取到的是一组IP:(ip1, ip2)
     * @return string
     */
    public static function getUserIP($onlyOne = true)
    {
        //从代理服务器开始 向真实IP获取 尽量获取到最真实IP
        $ip = '';
        foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_FROM', 'REMOTE_ADDR') as $v) {
            if (isset($_SERVER[$v])) {
                //指定是否只获取单独的IP
                if ($onlyOne && ! preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $_SERVER[$v])) {
                    continue;
                }
                $ip = $_SERVER[$v];
                break;
            }
        }
        return $ip;
    }

}