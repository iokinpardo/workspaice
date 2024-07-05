<?php

namespace App\Services;

use App\Models\Call;
use App\Models\Message;
use App\Models\Project;
use NunoDonato\AnthropicAPIPHP\Client;
use NunoDonato\AnthropicAPIPHP\Messages;
use NunoDonato\AnthropicAPIPHP\Tools;
use Orhanerday\OpenAi\OpenAi;

class AIService
{
    public $openai;
    public $ai;

    const MAX_TOKENS = 4096;
    const TEMPERATURE = 1;

    const MODEL = 'gpt-4o';

    public function __construct(public Project $project)
    {
        //$open_ai_key = getenv('OPENAI_API_KEY');
        $this->ai = new Client(getenv('ANTHROPIC_API_KEY'));
        //$this->openai = new OpenAi($open_ai_key);
    }

    public function appendMessage(string $message, string $role, $name = null, $tool_id = null, $input = null, $multiple = false)
    {
        $message = [
            'content' => $message,
            'role' => $role,

        ];
        if ($name) {
            $message['name'] = $name;
        }
        if ($tool_id) {
            $message['tool_id'] = $tool_id;
        }
        if ($input) {
            $message['input'] = $input;
        }

        $message['project_id'] = $this->project->id;
        $message['multiple'] = $multiple;
        Message::create($message);
    }

    public function sendMessage($input = null, $role = 'user', $name = null): void
    {
        $limit = 40;
        if ($input) {
            $this->appendMessage($input, $role, $name);
        }

        begin:
        $shouldRepeat = false;

        $previousMessages = $this->project->messages()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function ($message) {
                $array = [
                    'content' => $message->content,
                    'role' => $message->role,
                ];
                if ($message->name) {
                    $array['name'] = $message->name;
                }
                if ($message->tool_id) {
                    $array['tool_id'] = $message->tool_id;
                }
                if ($message->input) {
                    $array['input'] = $message->input;
                }
            return $array;
        })->reverse()->toArray();

        // reorder array keys, keeping values in place
        $previousMessages = array_values($previousMessages);

        $messages = new Messages();
        foreach ($previousMessages as $i => $message) {
            if (count($messages->messages()) == 0 && $message['role'] != 'user') {
                continue;
            }
            if ($message['role'] == 'tool_use' || $message['role'] == 'tool_result') {
                $count = count($previousMessages);

                if ($i < $count - 20) {
                    if (strlen($message['content']) > 1000) {
                        $message['content'] = "[removed due to length]";
                    }
                    if (isset($message['input']) && strlen(json_encode($message['input'])) > 1000) {
                        $message['input'] = [
                            'input' => 'removed due to length'
                        ];
                    }
                }

                $content = [
                    'type' => $message['role'],
                ];

                if ($message['role'] == 'tool_use') {
                    $content['input'] = $message['input'];
                    $content['id'] = $message['tool_id'];
                    $content['name'] = $message['content'];
                }
                if ($message['role'] == 'tool_result') {
                    $content['content'] = $message['content'];
                    $content['tool_use_id'] = $message['tool_id'];
                }
                $message['role'] = $message['role'] == 'tool_use' ? 'assistant' : 'user';
                $message['content'] = [$content];
            }

            // if is the last message in the array, append special content
            if ($i == count($previousMessages) - 1) {
                $meta = $this->getMetaContent();
                if (is_array($message['content'])) {
                    $last = count($message['content']) -1;
                    if ($message['role'] == 'user')
                    {
                        $message['content'][$last]['content'] = $meta . "\n<user>". $message['content'][$last]['content']."</user>";
                    } else {
                        $message['content'][$last]['content'] = $meta . "\n". $message['content'][$last]['content'];
                    }

                } else {
                    if ($message['role'] == 'user')
                    {
                        $message['content'] = $meta . "\n<user>". $message['content']."</user>";
                    } else {
                    $message['content'] = $meta . "\n". $message['content'];
                    }

                }
            }

            $messages->addMessage($message['role'], $message['content']);
        }

        if (count($messages->messages()) == 0
        || (count($messages->messages()) == 1 && $this->project->messages()->count() > 1)) {
            $limit+=20;
            if ($limit > 500) {
                throw new \Exception('Too many messages without user role. Aborting.');
            }
            goto begin;
        }


        $tools = new Tools();
        $tools->addToolsFromArray(getAvailableFunctions());

        $response = $this->ai->messages(Client::MODEL_3_5_SONNET, $messages, $this->buildSystemMessage(), $tools, [], 2000);

        if ($response['type'] == 'error') {
            dd($response['error']['message'], $message);
            throw new \Exception($response['error']['message']);
        }

        $prompt_tokens = $response['usage']['input_tokens'];
        $completion_tokens = $response['usage']['output_tokens'];

        Call::create([
            'project_id' => $this->project->id,
            'call' => json_encode($messages),
            'response' => $response,
            'prompt_tokens' => $prompt_tokens,
            'completion_tokens' => $completion_tokens,
            'message_count' => count($messages->messages()),
        ]);

        $content = $response['content'];


        foreach($content as $i => $message) {
            if (is_string($message)) {
                $this->appendMessage($message, 'assistant', multiple: false);
                echo "Assistant: ". $message."\n";
                continue;
            } else {
                switch($message['type']) {
                    case 'tool_use':
                        $name = $message['name'];
                        $args = $message['input'];
                        $this->appendMessage($message['name'], 'tool_use', $message['id'], $message['id'], $args);
                        try {
                            $result = call_user_func_array($name, [$this->project, ...$args]);
                        } catch (\Throwable $t) {
                            $result = "Error: " . $t->getMessage();
                        }
                        $this->appendMessage($result, 'tool_result', $message['name'], $message['id'], null, multiple: true);
                        $shouldRepeat = true;
                        break;
                    case 'text':
                        $multiple = count($content) > 1;
                        if (!$multiple) {
                            echo "Assistant: ". $message['text']."\n";
                        } else {
                            echo "Think: ". $message['text']."\n";
                            if ($i == 0) {
                                echo "working..";
                            }
                            echo ".";
                        }
                        $this->appendMessage($message['text'], 'assistant', multiple: $multiple);
                        break;
                }
            }
        }

        if ($shouldRepeat) {
            goto begin;
        }
    }

    public function buildSystemMessage(): string
    {


        $prompt = file_get_contents(storage_path('app/prompts/system.txt'));

        $msg = $prompt;
        $msg .= "<ProjectInformation>\n";
        $msg .= "Name: {$this->project->name}\n";
        $msg .= "Path: {$this->project->full_path}\n";
        $msg .= "Description: {$this->project->description}\n";
        $msg .= "Technical Specs: {$this->project->technical_specs}\n";
        $msg .= "</ProjectInformation>\n";
        return $msg;
    }

    public function getMetaContent()
    {
        $meta = '';
        /*$fileBuffer = "<FileBuffer>\n";
        foreach ($this->project->files ?? [] as $file) {
            try {
                $contents = file_get_contents($file);
            } catch (\Throwable $t) {
                // remove the file
                $this->project->files = array_diff($this->project->files, [$file]);
                $this->project->save();

                continue;
            }
            $fileBuffer .= "<File path='$file'>\n";
            $fileBuffer .= ($contents ?? '(error: file not found)');
            $fileBuffer .= "\n</File>\n";
        }
        $fileBuffer .= "</FileBuffer>\n";

        $meta = $fileBuffer;*/

        $meta .= "<SystemInformation>\n{$this->project->system_description}\nDate:". now()->format('Y-m-d H:i:s')."\n</SystemInformation>\n";
        $meta .= "<Tasks>\n{$this->project->tasks}\n</Tasks>\n";
        $meta .= "<Notes>\n{$this->project->notes}</Notes>";
        return $meta;
    }
}
