<?php

class DreameVacuum extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterVariableString("Test", "Test", "~TextBox", 1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }
}
