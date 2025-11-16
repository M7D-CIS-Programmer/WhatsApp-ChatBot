<?php

namespace App\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $to;
    protected string $from;
    protected string $body;
    protected ?string $mediaUrl;
    protected string $twilioSid;
    protected string $twilioToken;

    /**
     * Create a new job instance.
     */
    public function __construct(
        string $to,
        string $from,
        string $body,
        string $twilioSid,
        string $twilioToken,
        ?string $mediaUrl = null
    ) {
        $this->to = $to;
        $this->from = $from;
        $this->body = $body;
        $this->twilioSid = $twilioSid;
        $this->twilioToken = $twilioToken;
        $this->mediaUrl = $mediaUrl;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $twilio = new Client($this->twilioSid, $this->twilioToken);
            $params = [
                'from' => $this->from,
                'body' => $this->body,
            ];
            if (!empty($this->mediaUrl)) {
                $params['mediaUrl'] = [$this->mediaUrl];
            }

            $twilio->messages->create($this->to, $params);
            Log::info('Delayed WhatsApp message sent', [
                'to' => $this->to,
                'has_media' => !empty($this->mediaUrl),
            ]);
        } catch (Exception $e) {
            Log::error('Failed to send delayed WhatsApp message', [
                'error' => $e->getMessage(),
                'to' => $this->to,
            ]);
            throw $e;
        }
    }
}
