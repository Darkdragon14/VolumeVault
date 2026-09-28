<?php

namespace App\Services\Agents;

use App\Services\Logging\AppendRunLog;
use Illuminate\Database\Eloquent\Model;

class AgentOperationRunLog extends AppendRunLog
{
    public function __construct(private readonly AgentOperationRedactor $redactor) {}

    public function handle(Model $run, ?string $message): void
    {
        parent::handle($run, $this->redactor->clean($message));
    }
}
