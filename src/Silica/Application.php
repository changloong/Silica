<?php

/*
 * This file is part of the Silica framework.
 *
 * (c) Chang Loong <changlon@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Silica ;

use Exception;
use ArrayAccess;
use Closure;
use stdClass;

/**
 * The Silica framework class.
 *
 * @author Chang Loong <changlon@gmail.com>
 */
class Application implements ArrayAccess
{
    protected $values;
    private $listeners;
    private $initialized;
    
     /**
     * Instantiate a new Application.
     *
     * Objects and parameters can be passed as argument to the constructor.
     *
     * @param array $values The parameters or objects.
     */
    public function __construct (array $values = array()) {
        $this->values       = array();
        $this->listeners    = array() ;
        
        $this['debug'] = true ;
        $this['charset'] = 'UTF-8';
        $this['locale'] = 'en' ;
        foreach ($values as $key => $value) {
            $this[$key] = $value;
        }
    }
 
    public function offsetSet($id, $value) {
        if ( array_key_exists($id, $this->values) && Closure instanceof $this->values[$id] ) {
            throw new InvalidArgumentException(sprintf('Identifier "%s" is already defined.', $id));
        } 
        $this->values[$id] = $value ;
        if (isset($this->listeners[$id])) {
            foreach ($this->listeners[$id] as $listener) {
               $listener($this);
            }
        }
    }
 
    public function offsetGet($id) {
        if (!array_key_exists($id, $this->values)) {
            throw new InvalidArgumentException(sprintf('Identifier "%s" is not defined.', $id));
        } 
        $isFactory = is_object($this->values[$id]) && method_exists($this->values[$id], '__invoke');
        $this->initialized[$id] = true ;
        return $isFactory ? $this->values[$id]($this) : $this->values[$id];
    }
 
    public function offsetExists($id) {
        return array_key_exists($id, $this->values);
    }
 
    public function offsetUnset($id) {
        unset($this->values[$id]) ;
    }
    
    public function share($id, Closure $callable) {
        if ( array_key_exists($id, $this->values) ) {
            throw new InvalidArgumentException(sprintf('Identifier "%s" is already defined.', $id));
        } 
        $this[$id] = function ($c) use ($callable) {
            static $object ;
            if (null === $object) {
                $object = $callable($c) ;
            } 
            return $object ;
        } ;
        return $this ;
    }
 
    public function protect( $id, Closure $callable) {
        if ( array_key_exists($id, $this->values) ) {
            throw new InvalidArgumentException(sprintf('Identifier "%s" is already defined.', $id));
        }
        $this[$id] = function ($c) use ($callable) {
            return $callable ;
        } ;
        return $this ;
    }
 
    public function extend($id, Closure $callable) {
        if (!array_key_exists($id, $this->values)) {
            throw new InvalidArgumentException(sprintf('Identifier "%s" is not defined.', $id));
        }
 
        $factory = $this->values[$id];
 
        if (!($factory instanceof Closure)) {
            throw new InvalidArgumentException(sprintf('Identifier "%s" does not contain an object definition.', $id));
        }
 
        $this[$id] = function ($c) use ($callable, $factory) {
            return $callable($factory($c), $c);
        };
        
        return $this ;
    }
    
    public function initialized($id){
        return isset($this->initialized[$id]);
    }
 
    public function listen($id, Closure $callable ) {
        $this->listeners[$id][] = $callable;
        return $this ;
    }
 
    public function register($provider, array $values = array() ) {
         if (  !is_object($provider)  || !\method_exists($provider, 'register') ) {
             throw new \InvalidArgumentException(
                 'provider should have register method'
             );
         }
         $provider->register($this);
         foreach ($values as $key => $value) {
             $this[$key] = $value;
         }
         return $this ;
    }
    
    public function escape($text, $flags = ENT_COMPAT, $charset = null, $doubleEncode = true) {
        return htmlspecialchars($text, $flags, $charset ?: $this['charset'], $doubleEncode);
    }
    
    protected $controllers = array() ;
    
    public function match($pattern, Closure $to, $name = null, $method = null ) {
        $controller = new stdClass() ;
        $controller->pattern = $pattern ;
        $controller->to = $to ;
        $controller->method = $method ;
        if( $name ) {
            $this->controllers[$name] =  $controller ;
        } else {
            $this->controllers[] =  $controller ;
        }
        return $this ;
    }
    
    public function get($pattern, Closure $to, $name = null ) {
        $this->match($pattern, $to, $name, 'GET') ;
        return $this ;
    }
    
    public function post($pattern, Closure $to, $name = null ) {
        $this->match($pattern, $to, $name, 'POST') ;
        return $this ;
    }
    
    public function put($pattern, Closure $to, $name = null ) {
        $this->match($pattern, $to, $name, 'PUT') ;
        return $this ;
    }
    
    public function delete($pattern, Closure $to, $name = null) {
        $this->match($pattern, $to, $name, 'DELETE') ;
    }
    
    public function redirect($url, $status = 302) {
        header('Status: ' . $status) ;
        header('location: ' . $url ) ;
    }
    
    public function run( Closure $not_match_callback = null ) {
        
        $path    = urldecode( parse_url( '/' . ltrim($_SERVER['REQUEST_URI'], '/' ) , PHP_URL_PATH) );
        
        if( false !== strpos($path, '.') ) {
            $script_path    = trim( substr( $_SERVER['SCRIPT_FILENAME'], strlen($_SERVER['DOCUMENT_ROOT']) ) , '/') ;
            if( substr( ltrim( $path , '/') , 0, strlen($script_path) )  === $script_path ) {
                $path   = '/' .  ltrim( substr( $path, 1 + strlen($script_path) ), '/' )  ;
            } 
        }
        
        $method = $_SERVER['REQUEST_METHOD'] ;
        
        $_args  = null ;
        $call   = null ;
        $values = null ;
        
        foreach($this->controllers as $controller ) {
            if( $controller->method && $method != $controller->method ) {
                continue ;
            }
            if( false !== strpos($controller->pattern,  '%') ) {
                $last_param_fix = false ;
                $last_param   = '' ;
                $pattern      = rtrim($controller->pattern, '/') ;
                if( '/%?' === substr($pattern, -3 ) ) {
                    $last_param  = '(\/?|/[^/]+)' ;
                    $pattern    = substr($pattern, 0, -3) ;
                    $last_param_fix = true ;
                } else if ( '/%*' === substr($pattern, -3 ) ) {
                    $last_param =  '(\/?|/.*)' ;
                    $pattern    = substr($pattern, 0, -3) ;
                    $last_param_fix = true ;
                } else if ( '/%+' === substr($pattern, -3 ) ) {
                    $last_param =  '(.+)' ;
                    $pattern    = substr($pattern, 0, -2 ) ;
                } else {
                    $pattern .= '/?' ;
                }
                
                $regex = str_replace(['(',')','%'], ['(?:',')?','([^/]+)'],  $pattern ) . $last_param ;
                
                if (!preg_match('#^'.$regex.'$#', $path , $_args) ) {
                    continue ;
                }
                array_shift($_args);
                
                $last_index = count($_args) -1 ;
                if( $last_param_fix ) {
                    if( '/' == substr($_args[ $last_index ], 0, 1)  ) {
                        $_args[ $last_index ]   = substr($_args[ $last_index ], 1 ) ;
                    }
                }
                
                if( '' == $_args[ $last_index ] ) {
                    $_args[ $last_index ] = null ;
                }
                $call   = $controller->to ;
                break ;
            } else if( false !== strpos($controller->pattern, ':') ) {
                /**
                 * @FIXME this need refactor
                 */
                $pattern      = rtrim($controller->pattern, '/') ;
                $regex = preg_replace('#:([\w]+)#', '(?<\\1>[^/]+)', str_replace(['*', ')'], ['[^/]+', ')?'], $pattern ) ) ;
                if (!preg_match('#^'.$regex.'$#', $path , $values ) ) {
                    continue ;
                }
                preg_match_all('#:([\w]+)#', $pattern , $params, PREG_PATTERN_ORDER);
                $_args = array() ;
                foreach ($params[1] as $param) {
                  if (isset($values[$param])) $_args[] = urldecode($values[$param]) ;
                }
                $call   = $controller->to ;
                break ;
            } else {
                if( \trim( $controller->pattern , '/') !== \trim( $path , '/') ) {
                    continue ;
                }
                $call   = $controller->to ;
                break ;
            }
        }

        $response    = null ;
        if( !$call ) {
            if( $not_match_callback ) {
                $not_match_callback( $path ) ;
            } else {
                throw new Exception(sprintf("`%s` is not matched", $path)) ;
            }
        } else {
            if( $_args ) {
                $response    = call_user_func_array($call, $_args ) ;
            } else {
                $response    = $call() ;
            }
        }
    }
}
 
