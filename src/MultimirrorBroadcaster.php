<?php

namespace Multimirror;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use function is_bool;
use function is_string;
use function json_encode;
use function strlen;
use function strncmp;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class MultimirrorBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    private $MULTIMIRROR_HOST = 'https://socket.multimirror.io';

    protected $multimirrorAppKey;
    protected $multimirrorAppSecret;

    /**
     * Create a new broadcaster instance.
     */
    public function __construct(array $config)
    {

        if(isset($_ENV['MULTIMIRROR_HOST']) && $_ENV['MULTIMIRROR_HOST']){

            $this->MULTIMIRROR_HOST = $_ENV['MULTIMIRROR_HOST'];
        }
        $this->multimirrorAppKey = $config['app_key'];
        $this->multimirrorAppSecret = $config['app_secret'];
    }

    /**
     * Authenticate the incoming request for a given channel.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
     *
     * @return mixed
     */
    public function auth($request)
    {
        $channelName = $this->normalizeChannelName($request->channel_name);

        if ($this->isGuardedChannel($request->channel_name) &&
            !$this->retrieveUser($request, $channelName)) {
            throw new AccessDeniedHttpException();
        }


        return parent::verifyUserCanAccessChannel(
            $request,
            $channelName);
    }

    /**
     * Return the valid authentication response.
     *
     * @param \Illuminate\Http\Request $request
     * @param mixed $result
     *
     * @return mixed
     */
    public function validAuthenticationResponse($request, $result)
    {
        if (0 === strncmp($request->channel_name, 'private', strlen('private'))) {
            return $this->authPrivate($request);
        }

        $channelName = $this->normalizeChannelName($request->channel_name);

        return $this->authPresence(
            $request->channel_name,
            $request->socket_id,
            $this->retrieveUser($request, $channelName)->getAuthIdentifier(),
            $result
        );
    }

    /**
     * Broadcast the given event.
     *
     * @param array $channels
     * @param string $event
     * @param array $payload
     *
     * @return \Illuminate\Http\Client\Response
     * @throws \Illuminate\Broadcasting\BroadcastException
     */
    public function broadcast(array $channels, $event, array $payload = [])
    {
        $socket = Arr::pull($payload, 'socket');

        $response = $this->trigger(
            $this->formatChannels($channels),
            $event,
            $payload,
            $socket
        );

        if ($response->status() >= 200
            && $response->status() <= 299
            && ($json = $response->json())) {
            return $response;
        }

        throw new BroadcastException(
            is_bool($response) ? 'Failed to connect to Laravel Websockets.' : $response
        );
    }

    /**
     * Trigger an event by providing event name and payload.
     * Optionally provide a socket ID to exclude a client (most likely the sender).
     *
     * @param $channels         A channel name or an array of channel names to publish the event on.
     * @param $event
     * @param $data             Event data
     * @param $connectionId [optional]
     *
     * @return \Illuminate\Http\Client\Response
     */
    public function trigger($channels, $event, $data, $connectionId)
    {
        if (true === is_string($channels)) {
            $channels = [$channels];
        }

        $url = $this->MULTIMIRROR_HOST . '/apps/' . $this->multimirrorAppKey . '/events?auth_key=' . $this->multimirrorAppSecret;

        $response = null;
        foreach ($channels as $channel) {
            $response = Http::
            withHeaders(['Accept' => 'application/json'])
                ->post($url, [
                    'channel' => $channel,
                    'name' => $event,
                    'data' => json_encode($data),
                    'connection_id' => $connectionId,
                ]);
        }
        return $response;
    }


    /**
     * @param $request
     * @return string
     */
    public function authPrivate($request)
    {
        return $this->socket_auth($request->channel_name, $request->socket_id);
    }

    /**
     * @param string $channel
     * @param $connectionId
     * @param $uid
     * @param $authResults
     *
     * @return array
     */
    public function authPresence(string $channel, $connectionId, $uid, $authResults)
    {
        return $authResults;
    }

    /**
     * Creates a socket signature.
     *
     * @param string $channel
     * @param string $socket_id
     * @param string $custom_data
     *
     * @return string Json encoded authentication string.
     */
    public function socket_auth($channel, $socket_id, $custom_data = null)
    {

        if ($custom_data) {
            $signature = hash_hmac('sha256', $socket_id . ':' . $channel . ':' . $custom_data, $this->multimirrorAppSecret, false);
        } else {
            $signature = hash_hmac('sha256', $socket_id . ':' . $channel, $this->multimirrorAppSecret, false);
        }

        $signature = array('auth' => $this->multimirrorAppKey . ':' . $signature);
        // add the custom data if it has been supplied
        if ($custom_data) {
            $signature['channel_data'] = $custom_data;
        }

        $is_encrypted_channel = false;
        if ($is_encrypted_channel) {
            if (!is_null($this->crypto)) {
                $signature['shared_secret'] = base64_encode($this->crypto->generate_shared_secret($channel));
            } else {
            }
        }

        return json_encode($signature, JSON_UNESCAPED_SLASHES);
    }
}
