<?php

namespace Aerys\Handlers\WorkerPool;

use Amp\Async\ProtocolException,
    Amp\Async\Processes\Io\Frame,
    Amp\Async\Processes\Io\Message,
    Amp\Async\Processes\Io\FrameParser,
    Amp\Async\Processes\Io\FrameWriter;

class WorkerService {
    
    private $parser;
    private $writer;
    private $controller;
    private $frames = [];
    private $cachedFrameCount = 0;
    
    function __construct(FrameParser $parser, FrameWriter $writer, callable $controller) {
        $this->parser = $parser;
        $this->writer = $writer;
        $this->controller = $controller;
    }
    
    function onReadable() {
        if (!$frame = $this->parser->parse()) {
            return;
        }
        
        $this->frames[] = $frame;
        $this->cachedFrameCount++;
        
        if (!$frame->isFin()) {
            return;
        }
        
        if ($this->cachedFrameCount == 1) {
            $payload = $frame->getPayload();
            $payload = is_resource($payload) ? stream_get_contents($payload) : $payload;
        } else {
            $msg = new Message($this->frames);
            $payload = $msg->getPayload();
        }
        
        $this->frames = [];
        $this->cachedFrameCount = 0;
        
        list($procedure, $jsonAsgiEnv) = unserialize($payload);
        
        $asgiEnv = json_decode($jsonAsgiEnv, TRUE);
        
        $this->invokeController($asgiEnv);
    }
    
    private function invokeController(array $asgiEnv) {
        $controller = $this->controller;
        $asgiResponse = $controller($asgiEnv);
        $entityBody = $asgiResponse[3];
        
        if ($entityBody instanceof \Iterator) {
        
            list($status, $reason, $headers) = $asgiResponse;
            
            $firstFramePayload = json_encode([$status, $reason, $headers]);
            $length = strlen($firstFramePayload);
            $frame = new Frame($fin = 0, $rsv = 0, Frame::OP_DATA, $firstFramePayload, $length);
            $this->writer->write($frame);
            
            while ($entityBody->valid()) {
                $bodyChunk = $entityBody->current();
                
                if (NULL !== $bodyChunk) {
                    $length = strlen($bodyChunk);
                    $frame = new Frame($fin = 0, $rsv = 0, Frame::OP_DATA, $bodyChunk, $length);
                    $this->writer->write($frame);
                }
                
                $entityBody->next();
            }
            
            $frame = new Frame($fin = 1, $rsv = 0, Frame::OP_DATA, $payload = '', $length = 0);
            $this->writer->write($frame);
            
        } elseif (!is_resource($entityBody) && ($jsonAsgiResponse = json_encode($asgiResponse))) {
        
            $length = strlen($jsonAsgiResponse);
            $frame = new Frame($fin = 1, $rsv = 0, Frame::OP_DATA, $jsonAsgiResponse, $length);
            $this->writer->write($frame);
            
        } else {
            throw new ProtocolException(
                'Failed encoding response for transport'
            );
        }
    }
}

