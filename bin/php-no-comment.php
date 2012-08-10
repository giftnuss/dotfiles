#!/usr/bin/env php
<?php

require_once ('PHP/Beautifier.php');
require_once ('PHP/Beautifier/Batch.php');

$oBeautifier = new PHP_Beautifier();
$oBeautifier->startLog();

$oBeautifier->setIndentChar(' ');   // use space not tabs
$oBeautifier->setIndentNumber(4);   
$oBeautifier->setNewLine(chr(10));  // use UNIX-Line endings

class PHP_Beautifier_Filter_Comments extends PHP_Beautifier_Filter
{
    public function t_doc_comment($sTag)
    {
        return true;
    }
    
    public function t_comment($sTag)
    {
        return true;
    }
    
    public function t_open_tag($sTag)
    {
        return true;
    }
    
    public function t_close_tag($sTag)
    {
        return true;
    }
}

$oBeautifier->addFilterObject(new PHP_Beautifier_Filter_Comments($oBeautifier));

$in = 'php://stdin';
$out = 'php://stdout';

$oBeautifier->setInputFile($in);
$oBeautifier->setOutputFile($out);
            
$oBeautifier->process();
$oBeautifier->save();