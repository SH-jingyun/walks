<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */


class model {
    
    public function __get($name) {
        $className = $name . 'Model';
        return new $className();
    }
}