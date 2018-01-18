<?php

/**
 * SilverEngine  - PHP MVC framework
 *
 * @package   SilverEngine
 * @author    SilverEngine Team
 * @copyright 2015-2017
 * @license   MIT
 * @link      https://github.com/SilverEngine/Framework
 */

namespace Silver\Core;

use Silver\Http\Request;
use Silver\Http\Response;
use Silver\Exception\NotFoundException;

/**
 * Class Kernel
 *
 * @package Silver\Core
 */
class Kernel
{

    // Services to be loaded
    private $app;
    private $services = [];
    private $middlewares = [];

    /**
     *
     */
    public function loadMiddlewares()
    {
        foreach (Env::get('middlewares', []) as $mw) {
            $this->middlewares[] = new $mw;
        }

    }


    /**
     * @param Request  $req
     * @param Response $res
     */
    public function loadServices(Request $req, Response $res)
    {
        $services = Env::get('services', []);
        foreach ($services as $service_class) {
            $this->services[] = $s = new $service_class($this);
            $this->app->register($s);
        }

        foreach ($this->services as $service) {
            $service->before($req, $res);
        }
    }

    /**
     * @param Request  $req
     * @param Response $res
     */
    public function finalizeServices(Request $req, Response $res)
    {
        foreach ($this->services as $service) {
            $service->after($req, $res);
        }
    }

    /**
     *
     */
    public function loadRoutes()
    {
        foreach (Env::get('routes', []) as $route)
        {
          // ndd($route);
            if($route == 'App/Routes/Auth')
            {
              if(is_file(ROOT. $route.EXT))
                include_once ROOT . $route . EXT;
            }
            else {
                include_once ROOT . $route . EXT;
            }
        }
    }

    /**
     * @param Request  $request
     * @param Response $response
     * @return mixed
     * @throws NotFoundException
     */
    public function handle(Request $request, Response $response)
    {
        if ($route = $request->route()) {
            $callable = $this->findCallable($route);

            return DI::call(
                $callable, array_merge(
                    $this->app->instances()->getAll(),
                    $route->variables()
                )
            );
        } else {
            throw new NotFoundException('Route for ' . $request->getUri() . ' not found.');
        }
    }

    /**
     * @param $mws
     * @param Request  $req
     * @param Response $res
     * @return mixed
     */
    protected function executeMiddlewares($mws, Request $req, Response $res)
    {
        $self = $this;
        if (count($mws) > 0) {
            $mw = array_shift($mws);

            return $mw->execute(
                $req, $res, function () use ($self, $mws, $req, $res) {
                    return $self->executeMiddlewares($mws, $req, $res);
                }
            );
        } else {
            return $this->handle($req, $res);
        }
    }

    /**
     * @param $route
     * @return array
     * @throws NotFoundException
     */
    private function findCallable($route)
    {
        $action = $route->action();
        if (is_callable($action)) {
            return $action;
        } else {
            list ($class, $method) = explode('@', $action);

            $folders = [
                $this->app->path(), // default path
                $this->app->systemPath(),
            ];

            // Primitive controllers loader
            foreach ($folders as $folder) {
                $full = $folder . 'Controllers/' . $class;
                $full_class = str_replace('/', '\\', $full . 'Controller');
                $full_path = $full . 'Controller' . EXT;

                if (file_exists($full_path)) {
                    include_once $full_path;

                    // Prepare controller
                    $c = new $full_class;

                    if (method_exists($c, $method)) {
                        return [$c, $method];
                    }

                    throw new NotFoundException("Action not found {$method}@{$class}");
                }
            }

            throw new NotFoundException("Controller not found: {$method}@{$class}");
        }
    }

    public function run()
    {
        $this->app = $app = App::instance();
        $app->register(
            $req = new Request,
            $res = new Response
        );
        $mws = $this->middlewares;

        $this->loadServices($req, $res);

        $ret = $this->executeMiddlewares($mws, $req, $res);

        if ($ret !== null) {
            $res->setBody($ret);
        }

        //FIXME: dont load from response make separate class that can access response
        $res->send();

        $this->finalizeServices($req, $res);
        exit;
    }

    /**
     * @param $function
     * @param array    $args
     * @return mixed
     * @throws \Exception
     */
    public static function call($function, $args = [])
    {
        if (is_callable($function)) {
            return $function(...$args);
        } elseif (is_array($function)) {
            list($controller, $method) = $function;

            return $controller->$method(...$args);
        } else {
            throw new \Exception("\Dont know how to call \$function");
        }
    }
}
