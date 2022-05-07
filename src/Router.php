<?php
/**
 * Librería para control de enrutamientos.
 *
 * El enrutamiento se determina de dos formas:
 * - A través de un parámetro POST definido usando `$this->bind($param)`.
 * - Detectado del REQUEST_URI (Por ej. "/micode/projects/edit/php-demo") siempre que `$this->autoDetect` = true.
 *   En este escenario, se intentará procesar scripts que sean incorrectamente enrutados por el servidor web.
 * Permite también definir todos los enrutamientos por medio de un archivo de configuración para
 * facilitar su modificación sin necesidad de reescribir código.
 *
 * @uses miframe/admin/baseclass
 * @author John Mejia
 * @since Abril 2022
 */

namespace miFrame\Admin;

class Router extends \miFrame\Admin\BaseClass {

	private $request_param = '';
	private $request = array();
	private $recibido = false;
	private $tipo_acceso = '';
	private $rutas = array();
	private $detour_handler = false;

	public $autoDetect = true;		// TRUE para interpretar el REQUEST_URI
	public $autoExport = false;		// TRUE para exportar valores capturados en request a $_REQUEST

	public function __construct() {

		$this->dirbase = $this->setDirbase();

	}

	/**
	 * Captura valor del parámetro asociado al enrutamiento.
	 * El paràmetro POST puede ser restringido a que sea recibido por POST, GET o
	 * por cualquiera de ellos (sin restricción).
	 * Puede modificarse este valor cuantas veces sea necesario.
	 * En caso de no encontrar $name en las variables POST, GET o REQUEST, intentará recuperarla del REQUEST_URI siempre
	 * que `$this->autoDetect` = true.
	 *
	 * @param string $name Nombre del parámetro REQUEST asociado.
	 * @param string $type Restricción al origen del dato: "p" solo por POST, "g" sólo por GET,  cualquier otro valor no restringe.
	 */
	function bind(string $name, string $type = '') {

		$this->request = array();
		$this->recibido = false;

		$name = trim($name);
		if ($name == '') { return; }

		$collector = '_REQUEST';
		$tipos = array('p' => '_POST', 'g' => '_GET');
		if (isset($tipos[$type])) { $collector = $tipos[$type]; }

		$this->recibido = isset($GLOBALS[$collector])
							&& isset($GLOBALS[$collector][$name])
							&& is_string($GLOBALS[$collector][$name]);
		if ($this->recibido) {
			// En este caso, siempre toma de $_REQUEST porque ahí están todos los valores.
			$this->request = explode('/', strtolower($GLOBALS[$collector][$name]));
			$this->tipo_acceso = strtolower(substr($collector, 1));
		}

		if (!$this->recibido && $this->autoDetect) {
			// En este caso, busca en el nombre del script que realiza la consulta.
			// Toma el nombre de quien invoca como el script base.

			$script_name = strtolower(str_replace('\\', '/', miframe_server_get('SCRIPT_FILENAME')));
			// Recupera URI sin argumentos
			$request_uri = parse_url(strtolower(miframe_server_get('REQUEST_URI')), PHP_URL_PATH);
			// Valida que el uri sea valido
			$len_base = strlen($this->dirbase);

			if ($request_uri != ''
			&& $script_name != ''
			&& $len_base > 1) {
				// Valida que no sea un path directo, esos deberían pasar directamente al archivo llamado.
				// Esto significa que el direccionamiento en el servidor web fue deficiente.
				// Intenta de todas formas resolverlo.
				// 	Ejemplos:
				// 	[REQUEST_URI] => /micode/projects/edit/php-demo
				// 	[SCRIPT_NAME] => /micode/projects/edit/php-demo
				// 	[SCRIPT_FILENAME] => xxxxxx\router.php
				// REQUEST_URI y SCRIPT_FILENAME son diferentes.
				// Debe validar tambien que no sea del tipo que invoca un script directamente:
				// [REQUEST_URI] => /micode/projects/edit/php-demo.php

				if ($request_uri == $this->documentRoot()) {
					// Puerta de entrada, nada que hacer.
				}
				elseif (strpos($script_name, $request_uri) !== false) {
					$this->detour();
				}
				elseif (substr($request_uri, 0, $len_base) == $this->dirbase) {
					$request_uri = substr($request_uri, $len_base);
					$this->recibido = ($request_uri != ''); // && $request_uri != 'index.php');
					if ($this->recibido) {
						$this->request = explode('/', $request_uri);
						$this->tipo_acceso = 'uri';
					}
				}
				else {
					$this->abort('Error no definido', 'No pudo interpretar la solicitud <b>' . $request_uri . '</b>.');
				}
			}
		}


		$this->request_param = $name;
		if ($this->recibido) {
			$this->params[$this->request_param] = implode('/', $this->request);
		}

		return $this->recibido;
	}

	/**
	 * Retorna el tipo de acceso detectado.
	 *
	 * @return string Tipo de acceso. Puede ser: "post", "get" o "request" (para enrutamientos detectados a través
	 *     de un parámetro POST) o "uri" (para detectados vía REQUEST_URI).
	 */
	public function accessType() {
		return $this->tipo_acceso;
	}

	/**
	 * Carga enrutamientos listados de un archivo .ini.
	 *
	 * Se deben definir dos grupos: "general" y "uri". Ejemplo:
	 *
	 *     [general]
	 *     default = (script a ejecutar cuando no recibe enrutamiento o el enrutamiento apunta al index.php)
	 *     abort = (script a ejecutar en respuesta a $this->abort())
	 *     [uri]
	 *     (enrutamiento) = (script a ejecutar)
	 *     ...
	 *
	 * Las reglas para la declaraciòn del enrutamiento se describen en la documentación de la función runOnce().
	 *
	 * @param string $filename Nombre del archivo .ini a cargar,
	 * @param bool $append TRUE para adicionar este listado a lecturas previas. FALSE ignora lecturas previas.
	 * @param string $dirbase Path a usar para ubicar los scripts.
	*/
	public function loadConfig(string $filename,  bool $append = true, string $dirbase = '') {

		if (count($this->rutas) <= 0 || !$append) {
			$this->rutas = array('general' => array(), 'uri' => array());
		}

		$this->rutas = inifiles_get_data($filename) + $this->rutas;

		// Complementa rutas
		if ($dirbase == '') {
			$track = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
			// Aviso: si se invoca en un parent::__construct() adiciona un nivel mas
			if (isset($track[0])) {
				$dirbase = dirname($track[0]['file']);
			}
		}

		if ($dirbase == '') { $dirbase = '.'; }

		foreach ($this->rutas as $tipo => $info) {
			foreach ($info as $reference => $filename) {
				if (!file_exists($filename)) {
					$filename = miframe_path($dirbase, $filename);
					$this->rutas[$tipo][$reference] = $filename;
				}
				if (!file_exists($filename)) {
					miframe_error('Archivo no encontrado para la referencia ' . $reference, $filename);
				}
			}
		}
	}

	/**
	 * Evalúa enrutamientos declarados en archivo .ini.
	 *
	 * @param bool $continue FALSE termina la ejecución del script si encuentra un enrutamiento valido. TRUE continua evaluando.
	 * @return bool TRUE si encuentra un enrutamiento valido, FALSE en otro caso.
	 */
	public function run(bool $continue = false) {

		// Evalua si no hay datos recibidos
		if (isset($this->rutas['general']['default'])) {
			$filename = $this->rutas['general']['default'];
			if ($this->empty($filename, $continue)) {
				return true;
			}
		}

		// Evalua rutas programadas
		foreach ($this->rutas['uri'] as $reference => $filename) {
			if ($this->runOnce($reference, $filename, $continue)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Evalúa enrutamiento.
	 *
	 * El path para el enrutamiento debe declararse siguiendo uno de los siguientes formatos:
	 *
	 * - [ path script relativo a $this->path_scripts (el archivo no contiene la extensión) ]
	 * - [ path script relativo a $this->path_scripts ] /? [ arg1 / arg2 / ... ]
	 *
	 * El segundo modelo permite el paso de parámetros de forma simplificada. Por ejemplo, si define
	 * "projects/edit/?app" y recibe "projects/edit/holamundo" ejecuta el script
	 * `[$this->path_scripts]/projects/edit.php` y genera $this->params[app] = "holamundo".
	 * Sin embargo, si recibe solamente "projects/edit" igualmente ejecuta el mismo script asignando
	 * a los parámetros esperados cadena vacia ('').
	 * En caso de declarar `$this->autoExport` = true, carga los valores de $this->params en $_REQUEST.
	 *
	 * @param string $reference Path para el enrutamiento.
	 * @param string $filename Script a ejecutar.
	 * @param bool $continue FALSE para terminar ejecución luego de evaluar con éxito el enrutamiento. FALSE continua evaluando.
	 * @return bool TRUE si encuentra un enrutamiento valido, FALSE en otro caso.
	 */
	public function runOnce(string $reference, string $filename = '', bool $continue = false) {

		$reference_arr = explode('/', strtolower($reference));
		$capturando = false;
		$nueva_reference = '';
		$ultimo_path = '';
		$this->params = array();

		$c = count($this->request);

		foreach ($reference_arr as $k => $path) {
			if (substr($path, 0, 1) == '?') {
				$path = substr($path, 1);
				$capturando = true;
			}
			if (!$capturando) {
				// Evaluando path
				if (!isset($this->request[$k]) || trim($this->request[$k]) !== $path) {
					return false;
				}
				if ($nueva_reference != '') { $nueva_reference .= '/'; }
				$nueva_reference .= $path;
			}
			else {
				// Registrando valores
				$valor = '';
				if (isset($this->request[$k])) { $valor = trim($this->request[$k]); }
				$this->params[$path] = $valor;
				$ultimo_path = $path;
			}
		}

		if ($ultimo_path != '' && $c > $k) {
			for ($k = $k + 1; $k < $c; $k ++) {
				$this->params[$ultimo_path] .= '/' . trim($this->request[$k]);
			}
		}

		// Actualiza valor del parametro a buscar
		$this->params[$this->request_param] = $nueva_reference;

		if ($filename == '') { $filename = $nueva_reference; }

		// Exporta parametros al request
		if ($this->autoExport) { $this->export($_REQUEST); }

		return $this->include($filename, $reference, $continue);
	}

	/**
	 * Acción a realizar si no se detecta enrutamiento.
	 *
	 * @param string $filename Script a ejecutar.
	 * @param bool $continue FALSE para terminar ejecución luego de evaluar con éxito el enrutamiento. FALSE continua evaluando.
	 * @return bool TRUE si encuentra un enrutamiento valido, FALSE en otro caso.
	 */
	public function empty(string $filename, bool $continue = false) {

		if (!$this->recibido) {
			return $this->include($filename, '(empty)', $continue);
		}

		return false;
	}

	/**
	 * Ejecuta script.
	 * El script se ejecuta en un entorno aislado de la clase actual pero recibe esta clase com parámetro bajo el nombre `$Router`.
	 *
	 * @param string $filename Script a ejecutar.
	 * @param string $reference Path para el enrutamiento (para documentación si `$this->debug` = true).
	 * @param bool $continue FALSE para terminar ejecución luego de evaluar con éxito el enrutamiento. FALSE continua evaluando.
	 * @return bool TRUE si encuentra un enrutamiento valido, FALSE en otro caso.
	 */
	private function include(string $filename, string $reference = '', bool $continue = false) {

		if (file_exists($filename)) {
			// Valida siempre para reportar el error desde el primer momento.
			// Solo es critico cuando efectivamente hay una coincidencia.

			if ($this->debug) {
				$info = $reference;
				if ($info != '') { $info .= ' --> '; }
				$info .= $filename;
				error_log('MIFRAME/ROUTER ' . $info);
			}

			// Ejecuta include asegurando que esté aislado para no acceder a elementos privados de esta clase
			$include_fun = static function ($filename, &$Router) {
					include_once $filename;
				};
			$include_fun($filename, $this);

			if (!$continue) { exit; }

			return true;
		}

		return false;
	}

	/**
	 * Asocia script a ejecutar al invocar `$this->abort()`.
	 *
	 * @param string $filename Script a ejecutar.
	 */
	public function abortHandler(string $filename) {

		$this->rutas['general']['abort'] = $filename;
	}

	/**
	 * Ejecuta script cuando se aborta o cancela un enrutamiento al invocar `$this->abort()`.
	 * Almacena los valores de título y mensaje en `$this->params` para permitir que sean luego recuperados para
	 * su uso desde el script invocado.
	 *
	 * @param string $title Titulo
	 * @param string $message Mensaje
	 */
	public function abort(string $title, string $message) {

		if (isset($this->rutas['general']['abort'])) {
			$this->params['title'] = $title;
			$this->params['message'] = $message;
			$this->include($this->rutas['general']['abort'], '(abort)', false);
		}

		// Si no pudo ejecutar lo anterior, presenta mensaje base
		// Mensaje con error a pantalla
		echo miframe_box($title, $message, 'critical', '', false);

		exit;
	}

	/**
	 * Procedimiento adicional a ejecutar al ejecurtar `$this->detour()`.
	 *
	 * @param callable $function Función a ejecutar.
	 */
	public function detourCall(callable $function) {
		$this->detour_handler = $function;
	}

	/**
	 * Ejecuta script que no está asociados a alguno de los enrutamientos declarados.
	 * Esto usualmente permite al sistema intentar recuperar scripts recibidos por enrutamientos erróneos realizados
	 * por el servidor web y detectados al evaluar el REQUEST_URI.
	 * También puede usarse para ejecutar scripts en un entorno aislado al actual (por ejemplo, suspender uso de Views).
	 *
	 * @param string $filenam Script a ejecutar. Si no se indica, recupera el script referido en REQUEST_URI.
	 */
	public function detour(string $filename = '') {

		$request_uri = parse_url(miframe_server_get('REQUEST_URI'), PHP_URL_PATH);
		if ($filename == '') {
			$filename = miframe_server_get('SCRIPT_FILENAME');
			if ($filename != '' && $this->debug) {
				error_log('MIFRAME/ROUTER Enrutamiento correcto? ' . $filename);
			}
		}

		if ($filename == '') { return; }

		if (strtolower(substr($filename, -4)) == '.php') {
			if (file_exists($filename)) {
				$include_fun = static function ($filename) {
					include_once $filename;
				};
				// Marca en modo debug
				if ($this->debug) {
					echo miframe_box('', 'Archivo <b>' . $filename . '</b> ejecutado desde <b>Miframe\Router</b>.', 'info', 'Si este no era el comportamiento esperado, favor revisar enrutamiento en el servidor web.');
				}
				// Suspende cualquier proceso en curso
				if (is_callable($this->detour_handler)) {
					call_user_func($this->detour_handler);
				}
				// Cambia al directorio del archivo
				chdir(dirname($filename));
				// Ejecuta
				$include_fun($filename);
				exit;
			}
			else {
				$this->abort(
					'Archivo no encontrado',
					'<p>Solicitud no puede ser procesada para <b>' . $request_uri . '</b></p>' .
					'El archivo solicitado no existe en el servidor.'
					);
			}
		}
		else {
			// No es un archivo php, falla de enrutamiento?
			$this->abort(
				'Falla de enrutamiento',
				'<p>Solicitud no puede ser procesada para <b>' . $request_uri . '</b></p>' .
				'El servidor web requiere revisión para prevenir que estos enrutamientos sean direccionados a <b>' . $this->documentRoot() . '</b>.'
				);
		}
	}

}
