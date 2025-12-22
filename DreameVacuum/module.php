<?php

class DreameVacuum extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Test', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function Ping()
    {
        return 'pong';
    }
}
