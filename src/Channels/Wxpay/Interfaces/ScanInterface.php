<?php

namespace Doubear\Paysoul\Channels\Wxpay\Interfaces;

use ArrayObject;
use Closure;
use Doubear\Paysoul\Contracts\ChannelInterface;
use Doubear\Paysoul\Exceptions\HttpException;
use Doubear\Paysoul\Utils\ConfigSet;
use Doubear\Paysoul\Utils\HttpClient;

class ScanInterface implements ChannelInterface
{
    protected $gateway = 'https://api.mch.weixin.qq.com';

    protected $config;

    protected $http;

    public function __construct(ConfigSet $config)
    {
        $this->config = $config;

        if ($this->config->get('sandbox', false)) {
            $this->sandbox();
        }

        $this->http = new HttpClient([
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 5,
        ]);

        if ($this->sslEnabled()) {
            $this->http->set(CURLOPT_SSLCERTTYPE, 'PEM');
            $this->http->set(CURLOPT_SSLCERT, $this->config->get('ssl_cert_path'));
            $this->http->set(CURLOPT_SSLKEYTYPE, 'PEM');
            $this->http->set(CURLOPT_SSLKEY, $this->config->get('ssl_key_path'));
        }
    }

    public function sandbox()
    {
        $this->gateway = 'https://api.mch.weixin.qq.com/sandboxnew';
    }

    protected function toXml(array $values)
    {
        $xml = "<xml>";
        foreach ($values as $key => $val) {
            if (is_numeric($val)) {
                $xml .= "<" . $key . ">" . $val . "</" . $key . ">";
            } else {
                $xml .= "<" . $key . "><![CDATA[" . $val . "]]></" . $key . ">";
            }
        }
        $xml .= "</xml>";

        return $xml;
    }

    protected function fromXml($xml)
    {
        libxml_disable_entity_loader(true);
        return json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
    }

    protected function sslEnabled()
    {
        if (is_file($this->config->get('ssl_cert_path'))
            && is_file($this->config->get('ssl_key_path'))
        ) {
            return true;
        }

        return false;
    }

    protected function sendHttpRequest(string $api, array $data)
    {
        $payload = $this->toXml($data);

        return $this->http->post($this->gateway . $api, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => $payload,
        ]);
    }

    protected function getRequestBody($tradeType)
    {
        return [
            'appid'            => $this->config->get('app_id'),
            'mch_id'           => $this->config->get('mch_id'),
            'nonce_str'        => bin2hex(openssl_random_pseudo_bytes(16)),
            'spbill_create_ip' => $_SERVER['REMOTE_ADDR'],
            'notify_url'       => $this->config->get('notify_url'),
            'trade_type'       => $tradeType,
            'product_id'       => 0,
        ];
    }

    protected function sign(array $data, array $ignores = [])
    {
        $data = array_filter($data);

        foreach ($ignores as $key) {
            unset($data[$key]);
        }

        ksort($data);

        $s = urldecode(http_build_query($data) . '&key=' . $this->config->get('key'));

        return strtoupper(md5($s));
    }

    protected function handleHttpResponse(string $responseText)
    {
        $data     = $this->fromXml($responseText);
        $response = new ArrayObject($data, 3);

        if (false === $this->verify($data)) {
            throw new HttpException('签名校验失败');
        }

        if ($response->return_code != 'SUCCESS') {
            throw new HttpException($response->return_msg);
        }

        if ($response->result_code != 'SUCCESS') {
            throw new HttpException($response->err_code . ': ' . $response->err_code_des);
        }

        return $response;
    }

    public function verify(array $data)
    {
        if (false === isset($data['sign'])) {
            return false;
        }

        return $this->sign($data, ['sign']) == $data['sign'];
    }

    public function notify($payload, Closure $success, Closure $failure)
    {
        try {
            $response = $this->handleHttpResponse($payload);
            return $success($this, $response);
        } catch (HttpException $e) {
            return $failure($this, $e);
        }
    }

    public function deal($id, $subject, int $amount, array $extra = [])
    {
        $data = array_merge($this->getRequestBody('NATIVE'), [
            'out_trade_no' => $id,
            'body'         => $subject,
            'total_fee'    => $amount,
        ], $extra);

        $data['sign'] = $this->sign($data);

        $responseText = $this->sendHttpRequest('/pay/unifiedorder', $data);
        $response     = $this->handleHttpResponse($responseText);

        return $response->code_url;
    }

    public function query($id)
    {
        $data = array_merge($this->getRequestBody('NATIVE'), [
            'out_trade_no' => $id,
        ]);

        $data['sign'] = $this->sign($data);

        $responseText = $this->sendHttpRequest('/pay/orderquery', $data);
        $response     = $this->handleHttpResponse($responseText);

        return $response;
    }

    public function close($id)
    {
        $data = array_merge($this->getRequestBody('NATIVE'), [
            'out_trade_no' => $id,
        ]);

        $data['sign'] = $this->sign($data);

        $responseText = $this->sendHttpRequest('/pay/closeorder', $data);
        $response     = $this->handleHttpResponse($responseText);

        // return $response;
        return true;
    }

    public function refund($id, $reqId, int $amount, int $total)
    {
        $data = array_merge($this->getRequestBody('NATIVE'), [
            'out_trade_no'  => $id,
            'out_refund_no' => $reqId,
            'refund_fee'    => $amount,
            'total_fee'     => $total,
        ]);

        $data['sign'] = $this->sign($data);

        $responseText = $this->sendHttpRequest('/secapi/pay/refund', $data);
        $response     = $this->handleHttpResponse($responseText);

        return $response;
    }

    public function refundQuery($reqId)
    {
        $data = array_merge($this->getRequestBody('NATIVE'), [
            'out_refund_no' => $reqId,
        ]);

        $data['sign'] = $this->sign($data);

        $responseText = $this->sendHttpRequest('/pay/refundquery', $data);
        $response     = $this->handleHttpResponse($responseText);

        return $response;
    }

    public function respond($ok = false)
    {
        $response = [
            'return_code' => $ok ? 'SUCCESS' : 'FAIL',
            'return_msg'  => $ok ? 'OK' : '系统错误',
        ];

        return $this->toXml($response);
    }
}