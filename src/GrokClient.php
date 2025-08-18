<?php
namespace RapTToR\Grok;

class GrokClient
{
    private $apiKey;
    private $baseUrl = 'https://api.x.ai/v1';
    private $endpoint = '/chat/completions';
    private $capability = 'text';
    private $lastResponse = null;
    private $systemMessage = null;
    
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

    /**
     * Constructor
     *
     * @param string $apiKey xAI API key
     * @param string $baseUrl Base URL (default: https://api.x.ai/v1)
     */
    public function __construct(string $apiKey, ?string $baseUrl = null)
    {
        $this->apiKey = $apiKey;
        if ($baseUrl) {
            $this->baseUrl = rtrim($baseUrl, '/');
        }
    }

    /**
     * Set a system message to be automatically prepended to messages.
     */
    public function withSystem(string $system): self
    {
        $this->systemMessage = $system;
        return $this;
    }

    /**
     * Send a chat completion request to the GROK API
     */
    public function chat($options)
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
                $messages[] = ['role' => 'system', 'content' => $this->systemMessage];
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

    /**
     * Summary of prompt
     * @param mixed $payload
     * @throws \Exception
     * @return static
     */
    public function prompt($payload)
    {
        extract($payload);
        $this->lastResponse = false;
        try {
            $modelData = false;
            if (isset($this->models[$model])) {
                $modelData = $this->models[$model];
                if ($modelData && in_array($this->capability, $modelData['modalities'])) {
                    $headers = [
                        'Authorization: Bearer ' . $this->apiKey,
                        'Content-Type: application/json',
                        'Accept: application/json',
                    ];

                    /* $payload = [
                        'messages' => $messages,
                        'model' => $model,
                        'temperature' => $temperature,
                        'stream' => (isset($stream) && $stream) ? true : false,
                    ]; */
                    if (isset($stream) && $stream)
                        $payload['on_chunk'] = $stream;
                    if (isset($max_tokens) && $max_tokens > 0)
                        $payload['max_tokens'] = $max_tokens;

                    // var_dump($payload);

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_TIMEOUT, 3600);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $this->endpoint);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
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

                    $this->lastResponse = $response;  // <-- store for chaining
                } else {
                }
            } else {
                throw new \Exception('Invalid model name: ' . $model);
            }
        } catch (\Exception $e) {
            throw new \Exception('API error: ' . $e->getMessage());
        }
        return $this;
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

    public function raw(
        array $messages,
        string $model = 'grok-4',
        float $temperature = 0.7,
        bool $stream = false
    ): array {
        $this->chat($messages, $model, $temperature, $stream);
        return ($this->lastResponse) ? $this->response() : false;
    }

    public function capability($string)
    {
        $this->capability = $string;
        return $this;
    }

    public function endpoint($string)
    {
        $this->endpoint = $string;
        return $this;
    }

    /*
     * based on curl -X 'POST' https://api.x.ai/v1/images/generations \
     * -H 'accept: application/json' \
     * -H 'Authorization: Bearer <API_KEY>' \
     * -H 'Content-Type: application/json' \
     * -d '{
     *       "model": "grok-2-image",
     *       "prompt": "A cat in a tree",
     *       "response_format": "b64_json"
     *     }'
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
            ->endpoint('/images/generations')
            ->capability('image')
            ->prompt(array_merge($default, $options));
        return $result;
    }
}
