<?php declare(strict_types=1);

namespace RapTToR\Grok;

class GrokClient
{
    public int $timeout = 3600;
    private string $apiKey;
    private string $baseUrl = 'https://api.x.ai/v1';
    private string $endpoint = '/chat/completions';  // default endpoint
    private $endpointImage = '/images/generations';  // default endpoint
    /** @var 'text'|'image' */
    private $capability = 'text';
    /** @var array<string,mixed>|string|null */
    private ?string $lastResponse = null;
    private $systemMessage = null;
    private string $model = 'grok-4';
    /** @var array<string,mixed>|false */
    private $payload = false;

    /**
     * Known models + pricing (per M tokens or per image).
     */
    private $models = [
        'grok-4' => [
            'modalities' => ['text', 'image'],
            'capabilities' => ['text', 'vision', 'tool_calling', 'structured_outputs'],
            'context' => 256000,
            'rate_limits' => [
                'tokens_per_minute' => 2000000,
                'requests_per_minute' => 480
            ],
            'pricing' => [
                'input' => 3.0,  // per million tokens
                'output' => 15.0  // per million tokens
            ]
        ],
        'grok-3' => [
            'modalities' => ['text'],
            'capabilities' => ['text', 'reasoning'],
            'context' => 131072,
            'rate_limits' => [
                'requests_per_minute' => 600
            ],
            'pricing' => [
                'input' => 3.0,  // per million tokens
                'output' => 15.0  // per million tokens
            ]
        ],
        'grok-3-mini' => [
            'modalities' => ['text'],
            'capabilities' => ['text', 'reasoning'],
            'context' => 131072,
            'rate_limits' => [
                'requests_per_minute' => 480
            ],
            'pricing' => [
                'input' => 0.3,  // per million tokens
                'output' => 0.5  // per million tokens
            ]
        ],
        'grok-2-image' => [
            'modalities' => ['text', 'image'],
            'capabilities' => ['image_generation'],
            'context' => 131072,
            'rate_limits' => [
                'requests_per_minute' => 300
            ],
            'pricing' => [
                'output' => 0.07  // per image
            ]
        ]
    ];

    public static function fromEnv(?string $baseUrl = null): self
    {
        $apiKey = getenv('XAI_API_KEY');
        if (!$apiKey) {
            throw new \RuntimeException('XAI_API_KEY not set in environment');
        }
        return new self($apiKey, $baseUrl);
    }

    /**
     * Constructor
     *
     * @param string $apiKey xAI API key
     * @param string $baseUrl Base URL (default: https://api.x.ai/v1)
     */
    public function __construct(
        string $apiKey,
        ?string $baseUrl = null
    ) {
        $this->apiKey = $apiKey;
        if ($baseUrl) {
            $this->baseUrl = rtrim($baseUrl, '/');
        }
    }

    /**
     * Set a system message to be automatically prepended to messages.
     *
     * @param string $system System message
     * @returns GrokClient
     */
    public function withSystem(string $system): self
    {
        $this->systemMessage = $system;
        return $this;
    }

    /**
     * Send a chat completion request to the GROK API
     *
     * @param mixed $options String|Array of message objects with role and content
     * @returns GrokClient
     */
    public function chat($options): self
    {
        $default = array(
            'messages' => [],
            'model' => 'grok-4',
            'temperature' => 0.7,
            'stream' => false,  // false||callable method to call for streaming
            'max_tokens' => 4096,
        );

        if (is_string($options)) {
            $options = [
                'messages' => [
                    ['role' => 'user', 'content' => $options]
                ]
            ];
        }

        extract($options = array_merge($default, $options));

        // suport for sending one message as string;
        if (isset($messages) && is_string($messages))
            $messages = [[
                'role' => 'user',
                'content' => $messages
            ]];

        // sport for sending one message content
        if (isset($messages) && is_array($messages) && isset($messages['content']))
            $messages = [$messages];

        if ($this->systemMessage) {
            $hasSystemMessage = false;
            foreach ($messages as $index => $message) {
                if ($message['role'] == 'system') {
                    $hasSystemMessage = true;
                    break;
                }
            }
            if (!$hasSystemMessage) {
                array_unshift($opt['messages'], ['role' => 'system', 'content' => $this->systemMessage]);
            }
        }

        return $this
            ->endpoint('/chat/completions')
            ->prompt([
                'messages' => $messages,
                'model' => $model,
                'temperature' => $temperature,
                'stream' => $stream,
                'max_tokens' => $max_tokens
            ]);
    }

    public function validModel()
    {
        $model = $this->model;
        if (!isset($this->models[$model]))
            throw new \Exception("Model $model is not valid");

        return $this->models[$model];
    }

    /**
     * Summary of prompt
     * @param mixed $payload string|array by example
     * @throws \Exception
     * @return static
     *
     * @param mixed $example =[
     * 'messages' => [['role' => 'user', 'content' => '']],
     * 'model' => 'grok-4', // optional, but must be one of $this->models
     * 'temperature' => 0.7, // optional
     * 'stream' => false // false||callable method to call for streaming
     * 'max_tokens' => 4096 // optional
     * ]
     */
    public function prompt(array $payload, mixed $example = null): self
    {
        extract($payload);
        if (isset($payload['model']))  // set object model from parameters, for debugging
            $this->model($model = $payload['model']);
        $this->lastResponse = null;
        $modelData = $this->validModel();

        if ($modelData && in_array($this->capability, $modelData['modalities'])) {
            if (isset($stream) && $stream)
                $payload['on_chunk'] = $stream;

            if (isset($max_tokens) && $max_tokens > 0)
                $payload['max_tokens'] = $max_tokens;

            $this->payload = $payload;

            $response = $this->call();
            // do not convert response, as it may not be json always.
            $this->lastResponse = $response;  // <-- store for chaining
        } else {
            throw new \Exception("Model $model does not support capability $this->capability");
        }
        return $this;
    }

    /**
     * Call the API
     * @throws \Exception
     * @return bool|string
     */
    public function call()
    {
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $this->endpoint);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($this->payload, JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr) {
            throw new \Exception('cURL error: ' . $curlErr);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            // include body for easier debugging
            throw new \Exception("HTTP $httpCode: " . $response);
        }
        return $response;
    }

    /*
     * Convenience: return response text
     *
     * @param int $choiceIndex Choice index (default: 0)
     * @param string $fallback Fallback value (default: '')
     */
    public function result(int $choiceIndex = 0, string $fallback = ''): string
    {
        if (!$this->lastResponse) {
            return $fallback;
        }
        if (is_string($this->lastResponse)) {
            $response = json_decode($this->lastResponse, true);
        }

        if (!$response) {
            return $fallback;
        }

        // Try the common xAI/OpenAI-style paths:
        $choice = $response['choices'][$choiceIndex] ?? null;
        if (!$choice)
            return $fallback;

        // Chat API: message.content
        if (isset($choice['message']['content'])) {
            return (string) $choice['message']['content'];
        }

        // Some providers: choice.text
        if (isset($choice['text'])) {
            return (string) $choice['text'];
        }

        // Streaming delta (if ever captured): delta.content
        if (isset($choice['delta']['content'])) {
            return (string) $choice['delta']['content'];
        }

        return $fallback;
    }

    /*
     * Convenience: return response text
     *
     * @param int $choiceIndex Choice index (default: 0)
     * @param string $fallback Fallback value (default: '')
     */
    public function text(int $choiceIndex = 0, string $fallback = ''): string
    {
        return $this->result($choiceIndex, $fallback);
    }

    /**
     * Get the last response
     */
    public function response()
    {
        return $this->lastResponse;
    }

    /**
     * Convenience: return response id if present
     */
    public function id(): ?string
    {
        return $this->lastResponse['id'] ?? null;
    }

    /*
     * Raw request
     *
     * @param array $messages
     * @param string $model
     * @param float $temperature
     * @param bool $stream
     * @return array
     */

    public function raw(
        array $messages,
        string $model = 'grok-4',
        float $temperature = 0.7,
        bool $stream = false
    ): array {
        $this->chat([
            'messages' => $messages,
            'model' => $model,
            'temperature' => $temperature,
            'stream' => $stream
        ]);
        return ($this->lastResponse) ? $this->response() : false;
    }

    /**
     * Set capability
     * @param mixed $string
     * @return static
     */
    public function capability($string): self
    {
        $this->capability = $string;
        return $this;
    }

    /**
     * Set endpoint
     * @param mixed $string
     * @return static
     */
    public function endpoint($string): self
    {
        $this->endpoint = $string;
        return $this;
    }

    /*
     * Helper: generate an image
     *
     * @param array $options Options = [ // all optional except 'prompt'
     *     'prompt' => 'A cat in a tree',
     *     'model' => 'grok-2-image',
     *     'response_format' => 'b64_json',  // b64_json|url
     *     'n' => 1,  // number of images
     * ]
     *
     * @return GrokClient
     */
    public function image(
        $options,
    ) {
        $default = array(
            'prompt' => 'A cat in a tree',
            'model' => 'grok-2-image',
            // 'response_format' => 'b64_json',  // b64_json|url
            // 'n' => 1,  // number of images
        );
        $result = $this
            ->model($options['model'] ?? $default['model'])
            ->imageEndpoint()
            ->capability('image')
            ->prompt(array_merge($default, $options));
        return $result;
    }

    /**
     * Summary of model
     * @param mixed $model
     * @return static
     */
    public function model($model): self
    {
        $this->model = $model;
        return $this;
    }

    /*
     * Helper: analyze an image
     * $options=[
     * 'prompt' => 'Analyze this image',
     * 'image' => '<base64_image_string>',
     * 'detail' => 'high',  // high|auto|low
     * ]
     */
    public function analyze($options): self
    {
        $default = [
            'prompt' => 'Analyze this image',
            'image' => '<base64_image_string>',
            'detail' => 'high',  // high|auto|low
        ];
        extract($options = array_merge($default, $options));
        $result = $this
            ->chat([
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:image/jpeg;base64,' . $image,
                                'detail' => $detail,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ]
            ]);

        return $this;
    }

    /**
     * Set endpoint to image, enables chaining
     *
     * @return static
     */
    public function imageEndpoint(): self
    {
        $this->endpoint = $this->endpointImage;
        return $this;
    }

    /**
     * Rough cost estimate in USD based on model pricing table.
     * Provide token counts (not chars). Example:
     *   $client->costEstimate(inTokens: 12_000, outTokens: 2_000);
     */
    public function costEstimate(?int $inTokens = null, ?int $outTokens = null, int $images = 0): float
    {
        $m = $this->validModel();
        $pricing = $m['pricing'] ?? [];

        $cost = 0.0;
        if ($inTokens !== null && isset($pricing['input'])) {
            $cost += ($inTokens / 1_000_000) * (float) $pricing['input'];
        }
        if ($outTokens !== null && isset($pricing['output'])) {
            $cost += ($outTokens / 1_000_000) * (float) $pricing['output'];
        }
        if ($images > 0 && isset($pricing['output']) && !isset($pricing['input'])) {
            // image model: per-image output price
            $cost += $images * (float) $pricing['output'];
        }
        return round($cost, 6);
    }
}
