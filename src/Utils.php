<?php
namespace src\Utils;

use App\Models\Admin\Direccion;
use App\Sistema;
use Illuminate\Support\Facades\Storage;
use PHPMailer\PHPMailer\PHPMailer;
use App\Tracking;
use App\User;
use Illuminate\Support\Facades\Config;
use Gumlet\ImageResize;
use Gumlet\ImageResizeException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class Utils
{
    /**
     * Retorna el ID del sistema basándose en la URL.
     * Warning: Ya que se basa en la URL, puede que no coincida con los datos
     * de la base datos.(Sep.18) Mas adelante se modificara.
     */
    public static function sistemaIdFromURL(){
        $url = url()->current();

        $id = 0;

        if(strpos($url, '/odc/')){
            $id = 4;
        }else if(strpos($url, '/facturas/')){
            $id = 3;
        }else if(strpos($url, '/requisiciones/')){
            $id = 5;
        }else if(strpos($url, '/balanza/')){
            $id = 5;
        }else if(strpos($url, '/bancos/')){
            $id = 15;
        }else if(strpos($url, '/inventario/')){
            $id = 20;
        }else {
            new \Error('No se encontro el sistema');
        }

        return $id;
    }

    public static function enviarNotificacion($date, $messages, $filesToAttach = []){
        $storage = Storage::disk('daily');
        $rootFolderExists = $storage->exists('/');
        if(!$rootFolderExists){
            $storage->makeDirectory('/logs');
        }

        $groupedMessages = [];
        $tempMessage = '';
        $counter = 1;

        $daily = request()->daily;

        foreach($messages as $messageNoDaily => $message){
            if(is_string($message)){
                $msg = $message;

                if($tempMessage === $msg){
                    $counter++;
                }else {
                    $tempMessage = $msg;
                    $counter = 1;
                }
    
                $groupedMessages[$msg] = $counter;
            }else{
                $groupedMessages[$messageNoDaily] = $message;
            }
        }

        if(empty($messages)) 
            return;

        try {

            /**
             * LEER ARCHIVOS DE CORREOS
             */

            $correosExists = $storage->exists('correos.txt');
            if(!$correosExists){
                dd('El archivo correos.txt no existe.');
                return;
            }

            $correos = $storage->get('correos.txt');
            $correos = explode(PHP_EOL, $correos);
            $mails = [];
            foreach($correos as $linea){
                $arrs_mail = explode(',', $linea);
                if (!empty($arrs_mail[0]) && !empty($arrs_mail[1]))
                    $mails[] = $arrs_mail[0];
            }

            $rawMessage = '';
            $htmlMessage = '';
            foreach ($groupedMessages as $text => $registers){
                $rawMessage .= "({$registers} registros) {$text} \n";
                $htmlMessage  .= "<li>({$registers} registros) {$text}</li>";
            }

            if(is_array($date)){
                $date = implode(',', $date);
            }
            Utils::enviarCorreo('', [
                'sistema' => ' - Bancos', 
                'asunto' => 'Se ejecuto correctamente la tarea de Importación de Bancos - ' . $date,
                'contenido' => "
                    Registros:<br>
                    <ul>{$htmlMessage}</ul>
                ",
                'tipo_notificacion' => 'Notificación del sistema de Importación bancos.',
                'tipo' => 'Tarea Programada.'
            ], [
                'lramirez@buenaventurahoteles.com'
            ],
            true, 'emails.email2',  $filesToAttach);

            $storage->put('logs/'. $date.'.txt', $rawMessage);

            return response()->json([
                'message' => 'Mensaje enviado'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Retorna el string que especifico sin caracteres especiales
     *
     * @param string $string
     * @param bool $withFileExtension
     * @param bool|int $limit Si es true, se limita a 250, si es entero se utiliza como limite
     */
    public static function stringWithoutSpecialCharacters($string, $withFileExtension = false, $limit = false){

        $ext = '';
        $newString = $string;
        if($withFileExtension){
            $stringArr = explode('.', $string);
            $stringLen = count($stringArr);
            $ext = '.' . $stringArr[$stringLen - 1];
            array_pop($stringArr);
            $newString = implode('', $stringArr);
        }

        $newString = strtolower(trim(preg_replace('~[^0-9a-z]+~i', '_', html_entity_decode(preg_replace('~&([a-z]{1,2})(?:acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml);~i', '$1', htmlentities($newString, ENT_QUOTES, 'UTF-8')), ENT_QUOTES, 'UTF-8')), '_'));
        if(is_bool($limit) && $limit === true){
            $newString = str_limit($newString, 250);
        }else if(is_numeric($limit)){
            $newString = str_limit($newString, $limit);
        }

        $newString .= $ext;
        return $newString;
    }

    public static function extractExtension($str, $returnAppendedTo = null)
    {
        $strArr = explode('.', $str);
        $strArrLen = count($strArr);
        $ext = $strArr[$strArrLen - 1];

        if($returnAppendedTo) {
            return $returnAppendedTo . '.' . $ext;
        }

        return $ext;
    }

    public static function getEmpresaByURL(){
        $routeParams = request()->route()->parameters;

        if(array_key_exists('empresa', $parameters)){
            return $routeParams['empresa'];
        }

        return null;
    }

    public static function formatDateSQL($date_str){
        return date('Ymd',strtotime($date_str));

    }

    public static function track($id, $sistema, $old_value,  $new_value, $message){

        $trackStatus = new Tracking();
        $trackStatus->old_status = $old_value;
        $trackStatus->user_id = auth()->id();
        $trackStatus->new_status = $new_value;
        $trackStatus->details = $message;

        if($sistema === 'facturas'){
            $trackStatus->factura_id = $id;
        }else if($sistema === 'odc'){
            $trackStatus->orden_id = $id;
        }else if($sistema === 'requisiciones'){
            $trackStatus->requisicion_id = $id;
        }else if($sistema === 'balanza'){ // balanza
            $trackStatus->cuenta_id = $id;
        }else if($sistema === 'users'){ // balanza
            $trackStatus->edited_user_id = $id;
        }else if($sistema === 'empresas'){ // balanza
            $trackStatus->empresa_id = $id;
        }else if($sistema === 'sistemas'){ // balanza
            $trackStatus->sistema_id = $id;
        }else if($sistema === 'roles'){ // balanza
            $trackStatus->roles_id = $id;
        }else if($sistema === 'permisos'){ // balanza
            $trackStatus->permiso_id = $id;
        }

        $trackStatus->save();
    }

    /**
     * Formatear fecha en español
     * %d %h %Y
     * 01 ene. 2018
     * 
     * %B %Y
     * Enero 2018
     * @param $date
     * @param $format strftime
     */
    public static function formatFecha($date, $format = null, $upperCase = false){
        $date = strftime($format?:'%d %h %Y', strtotime($date));
        if($upperCase)
            $date = strtoupper($date);
        return  $date;
    }


    /**
     * Da formato al numero ingresado, si es float devuelve un numero con decimales
     * si no, devuelve un entero
     */
    public static function conertToFloat($value){
        $float = (float) $value;
        $splitted = explode('.', $value);
        dd($splitted);
    }

    /**
     * Convertir id de empresa a conexión
     */
    public static function generateEmpresaConn($empresaID = null, $TCA = null){
        if($empresaID === null)
            $empresaID = session()->get('inv_empresa_id', 1);

        $modo = self::modo();
        if($modo ==='local' && !$TCA)
            return 'sqlsrv';

        if($TCA === true){
            $conns = [
                1 => 'detalle_usuarios',
                3 => 'detalle_usuarios_hda',
                6 => 'detalle_usuarios',
                8 => 'detalle_usuarios_hda',
            ];
        }else{
            $conns = [
                1 => 'detalle_bvg',
                3 => 'detalle_hda',
                6 => 'detalle_vpr',
                8 => 'detalle_sab',
            ];
        }

        return $conns[$empresaID];
    }

    /**
     * Convertir id de la empresa a conexión sql
     */
    public static function generateSQLConnection($empresaID = null){
        if($empresaID === null)
            $empresaID = session()->get('inv_empresa_id', 1);

        $modo = self::modo();
        $debug = Config::get('app.debug');

        if($modo === 'local'){
            return $debug ? 'Temp_JLR.dbo.' : 'JLRTCA.dbo.';
        }

        $empSession = self::empresaFromSession();
        $conns = [
            0 => ($debug ? 'Temp_JLR.dbo.' : 'JLRTCA.dbo.'),
            1 => ($debug ? 'TCADBCAP.dbo.' : 'TCADBHVB.dbo.'),
            3 => '[172.16.1.25].TCADBHDA.dbo.',
            6 => ($debug ? 'TCADBPRU.dbo.' : 'TCADBVPR.dbo.'),
            8 => ('TCADBSAB.dbo.'),
        ];

        return $conns[$empresaID];
    }

    /**
     * Obtener id desde la session
     */
    public static function empresaFromSession(){
        return session()->get('inv_empresa_id', 1);
    }

    /**
     * Enviar correos
     * @param view $content 
     * @param boolean $isTemplate 
     * @param array $data
     * El parámetro $data debe de tener 'sistema','asunto', 'contenido'
     * @param array $emails 
     */
    
    public static function enviarCorreo($content, $data, $correos, $isTemplate = false, $template = null, $filesToAttach = []){
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        try {
            // $mail->SMTPDebug = 3;
            $mail->isSMTP();
            $mail->Host = config('mail.host');
            $mail->Port = config('mail.port');
            $mail->SMTPAuth = true;
            $mail->Username = config('mail.username');
            $mail->Password = config('mail.password');
            $mail->SMTPSecure = config('mail.encryption');
            $mail->setFrom(config('mail.username'), 'HB Solutions' . $data['sistema']);
            // $mail->SMTPAutoTLS = false;
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
            
            $mail->isHTML(true);

            // enviar a
            foreach($correos as $correo){
                $mail->addAddress($correo, '');
            }

            // Plantilla
            if($isTemplate){
                $imageRote = public_path('images/emails/');
                $logo = $imageRote . 'hoteles-buenaventura.JPG';
                $hoteles = $imageRote . 'hoteles.JPG';
                $footer = $imageRote . 'email-footer.png';
                $mail->addEmbeddedImage($logo, 'logo');
                $mail->addEmbeddedImage($hoteles, 'hoteles');
                $mail->addEmbeddedImage($footer, 'footer');

                $template_ = view($template, ['data' => $data])->render();
            }else{
                $template_ = $content;
            }

            foreach($filesToAttach as $fileName => $path){
                if(file_exists($path)){
                    $mail->addAttachment($path, $fileName);
                }
            }

            $mail->Subject = $data['asunto'];
            $mail->msgHTML($template_);

            $mail->send();

            return true;
        } catch (\Exception $e) {
            $error = $mail->ErrorInfo;

            $log = $e->getMessage();

            if(trim($error) !== ""){
                $log = $error;
            }

            $user = auth()->user() ?? auth('jwt')->user() ?? null;

            Log::error($log, [
                'correos' => $correos,
                'user_id' =>  $user->id ?? null,
                'correo' => $user->email ?? null,
                'url' => url(),
            ]);

            return false;
        }
    }

    /**
     * Retorna todas las firmas del usuario
     *
     * @param int|\App\User $userId
     * @param string $pluck
     */
    public static function firmasDeUsuario($userId = null, $pluck = false, $porEmpresa = false, $firmaEmpresa = false)
    {
        $user = self::currentUser($userId);

        if($user instanceof User){

            $firmas = $user->firmas;
            if($firmaEmpresa){
                $firmasEmpresas = [];
                foreach($firmas as $firma) {
                    $firmasEmpresas[] = ['empresa_id' => $firma->pivot->empresa_id, 'firma_id' => $firma->id];
                }
                return $firmasEmpresas;
            }

            if($porEmpresa) {
                $firmasPorEmpresa = [];

                foreach ($firmas as $firma){
                    $firmasPorEmpresa[$firma->pivot->empresa_id][] = $firma->id;
                }

                return $firmasPorEmpresa;
            }

            if($pluck){
                return $firmas->pluck($pluck)->toArray();
            }
            return $firmas;
        }

        return [];
    }

    /**
     * Retorna el usuario actual
     *
     * @param int|\App\User $userId
     * 
     * @return \App\User
     */
    private static function currentUser($userId = null)
    {
        $user = null;
        if ($userId instanceof User) {
            $user = $userId;
        } else if($userId){
            $user = User::find($userId);
        } else {
            $user = auth()->user() ?: auth('jwt')->user();
        }
        return $user;
    }

    /**
     * Retorna las direcciones que tenga el usuario.
     * 
     * Si el parámetro `$lasQuePuedeVer` es verdadero, se consultan las firmas del usuario y se retornan los Ids de las direcciones relacionadas a las firmas.
     *
     * @param int|\App\User $userId
     * @param string $pluck
     * @param boolean $lasQuePuedeVer
     * 
     * @return array
     */
    public static function direccionesPorUsuario($userId = null, $pluck = null, $lasQuePuedeVer = false)
    {
        $user = self::currentUser($userId);

        if ($user instanceof User) {
            // RETORNA LOS IDS DE LAS DIRECCIONES DONDE EL USUARIO TENGA LA FIRMA DE CADA DIRECCIÓN
            if ($lasQuePuedeVer) {
                $usrFirmas = self::firmasDeUsuario($user, 'id');

                $direcciones = Direccion::whereIn('firma_id', $usrFirmas)->get();
                return $direcciones->pluck('id')->toArray();
            }
            // RETORNA LAS DIRECCIONES QUE TIENE EL USUARIO
            $direcciones = $user->direcciones;
            if ($pluck){
                return $direcciones->pluck($pluck)->toArray();
            }
            return $direcciones->toArray();
        }

        return [];
    }

    /**
     * Retorna la direcciones que el usuario tiene asignadas
     * 
     * @param int $usrId Id del usuario de quien se consultaran las direcciones
     * @param string $pluck Nombre del campo a extraer
     * 
     * @return \Illuminate\Support\Collection|array
     */
    public static function usrDirecciones($usrId = null, $pluck = null) 
    {
        $user = self::currentUser($usrId);

        $direcciones = $user->direcciones;

        if($pluck){
            return $direcciones->pluck($pluck)->toArray();
        }

        return $direcciones;
    }

    /**
     * Retorna las direcciones donde el usuario tiene permiso.
     *
     * @param int $usrId Id del usuario de quien se consultaran las direcciones
     * @param string $pluck Nombre del campo a extraer
     * 
     * @return \Illuminate\Support\Collection|array
     */
    public static function usrPermisosDirecciones($usrId = null, $pluck = null, $wherePermiso = null, $conPermisos = null) 
    {
        $user = self::currentUser($usrId);

        $direcciones = $user->direccionesPermitidas;
    
        if($conPermisos) {
            $data = [];

            foreach ($direcciones as $dir){
                $data[$dir->id] = [
                    'ver' => $dir->pivot->ver ? true :false,
                    'crear' => $dir->pivot->crear ? true :false,
                    'editar' => $dir->pivot->editar ? true :false,
                    'eliminar' => $dir->pivot->eliminar ? true :false,
                    'renovar' => $dir->pivot->renovar ? true :false,
                    'confidenciales' => $dir->pivot->confidenciales ? true :false,
                    'crear_carpeta' => $dir->pivot->crear_carpeta ? true :false,
                ];
            }

            return $data;
        }

        if ($wherePermiso && $pluck) {
            return $direcciones->where('pivot.'.$wherePermiso, true)->pluck($pluck);
        }

        if($pluck) {
            return $direcciones->pluck($pluck)->toArray();
        }

        return $direcciones;
    }

    /**
     * Verifica si el usuario autenticado tiene el departamento especificado.
     *
     * @param int $deptoId
     * @param int $empresaId
     * @param int $userId
     */
    public static function tieneDepto($deptoId, $empresaId, $sistemaId = null, $userId = null): bool
    {
        $deptos = self::usrDepartamentos($sistemaId, true, $userId);

        if(array_key_exists($empresaId, $deptos) && is_array($deptos[$empresaId]) && in_array($deptoId, $deptos[$empresaId])) {
            return true;
        }

        return false;
    }

    /**
     * Retorna los departamentos del usuario
     * 
     * Por default retorna 
     * 
     * ```php
     * [empresaId => id, departamento_id => id]
     * ```
     * 
     * Agrupados por empresa:
     * 
     * ```php
     * [empresaId => [ deptoId, deptoId2, ... ]]
     * ```
     *
     * @param int $sistemaId Id del sistema donde se buscaran los departameantos
     * @param boolean $agrupadosPorEmpresa Indica si los departamentos estarán agrupados por el id de la empresa
     * @param int $userId Id del usuario de quien se consultaran los departamentos
     */
    public static function usrDepartamentos($sistemaId = null, $agrupadosPorEmpresa = false, $userId = null): array
    {
        $user = self::currentUser($userId);
        return $user->departamentosArr($agrupadosPorEmpresa, $sistemaId);
    }

    /**
     * Retorna los departamentos del usuario agrupados por los ids de las empresas
     *
     * ```php
     * [empresaId => [ deptoId, deptoId2, ... ]]
     * ```
     *
     * @param int $sistemaId Id del sistema donde se consultaran los departamentos
     * @param int $userId Id del usuario de quien se consultaran los departamentos
     */
    public static function usrDeptosPorEmpresasIds($sistemaId = null, $userId = null): array
    {
        return self::usrDepartamentos($sistemaId, true);
    }

    /**
     * Retorna los Ids de los departamentos que el usuario tiene por empresa
     *
     * @param int $empresaId
     * @param int $sistemaId
     * @param int $userId
     */
    public static function usrDeptosPorEmpresa($empresaId = null, $sistemaId = null, $userId = null): array
    {
        $deptos = self::usrDepartamentos($sistemaId, true, $userId);

        if (count($deptos) === 0) return [0];

        return $deptos[$empresaId] ?? [0];
    }

    public static function tienePermisoEnDir($dirId, $permiso, $userId = null)
    {
        $usrDirs = self::usrPermisosDirecciones($userId, null, null, true);

        if(array_key_exists($dirId, $usrDirs) && array_key_exists($permiso, $usrDirs[$dirId])) {
            $tienePermiso = $usrDirs[$dirId][$permiso] === true;
            if($tienePermiso) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retorna las direcciones donde el usuario tenga el permiso especificado
     *
     * @param string $permiso
     * @param string $pluck
     * @param int $userId
     */
    public static function direccionesPorPermiso($permiso, $pluck = null, $userId = null)
    {
        $usrDirs = self::usrPermisosDirecciones($userId);
        $direcciones = $usrDirs->where('pivot.' . $permiso, true);


        if($pluck){
            return $direcciones->pluck($pluck)->toArray();
        }

        return $direcciones;
    }
    
    public static function normalizeDecimal($val, int $precision = 4){
        $input = str_replace(' ', '', $val);
        $number = str_replace(',', '.', $input);
        if (strpos($number, '.')) {
            $groups = explode('.', str_replace(',', '.', $number));
            $lastGroup = array_pop($groups);
            $number = implode('', $groups) . '.' . $lastGroup;
        }
        return bcadd($number, 0, $precision);
    }

    /**
     * Itera entre el rango de fecha especificado
     *
     * @param string $start Y-m-d
     * @param string $end   Y-m-d
     * @param \Closure $callback recibe el dia de cada iteración
     * @param string $format formato de la fecha a retornar
     */
    public static function loopRangoDeFechas($start, $end, $callback, $format = null)
    {

        $start = new \DateTime($start);
        $end = new \DateTime($end);
        $end = $end->modify( '+1 day' ); 
        
        // intervalo de un día P = PERIODO 1D = 1DAY
        $interval = new \DateInterval('P1D');
        $daterange = new \DatePeriod($start, $interval, $end);
        
        foreach($daterange as $date){
            $_date = $date->format($format ?: 'Ymd');
            if($callback($_date) === 1){
                break;
            }
        }
    }

    public static function noCommas($number, $float = false){
        $reg = '/[^\d.]/';
        $clean = preg_replace($reg, '', $number);
        if($float){
            return floatval($clean);
        }else{
            return intval($clean);
        }
    }

    /**
     * Guarda imágenes en el storage. Crea la carpeta si no existe.
     * 
     * Comprime las imágenes y las guarda en formato .jpg.
     * 
     * Retorna un objeto stdClass con las propiedades:
     * - image: imagen tamaño completo
     * - thumb: miniatura de la imagen
     * 
     * @param string $name Nombre de la nueva imagen
     * @param string $tempPath Ruta temporal de la imagen
     * @param string $saveTo Ruta en donde se almacenara
     * @param boolean $thumbnail Si es true se genera una miniatura de la imagen
     * @return \stdClass
     */
    public static function saveImageToJpg($name, $tempPath, $saveTo, $thumbnail = false){
        try{
            // crear directorio si no existe
            File::isDirectory($saveTo) or File::makeDirectory($saveTo, 0755, true, true);

            // se generan los nombres de la imagen
            $imageName = $name.'.jpg';
            $imageThumbName = $name. '_120x120.jpg';

            $image = new ImageResize($tempPath);
            $image->resizeToBestFit(800, 800);
            $image->save($saveTo. $imageName, IMAGETYPE_JPEG, 70);

            // se genera el thumbnail cuando se especifica
            if($thumbnail){
                $image->crop(120,120);
                $image->save($saveTo. $imageThumbName, IMAGETYPE_JPEG);
            }

            // se retorna un objeto con el nombre de la imágenes generadas
            $img = new \stdClass();
            $img->image = $imageName;
            $img->thumb = $imageThumbName;

            return $img;
        }catch(ImageResizeException $e){
            return false;
        }
    }

    /**
     * Retorna una respuesta en formato json.
     * 
     * @param array $response Respuesta JSON
     * @param int $status Código http, default 200
     * 
     * @return \Illuminate\Http\Response
     */
    public static function json($response, $status = 200){
        return response()->json($response, $status);
    }

    /**
     * Genera el nombre del thumbnail de 120x120
     */
    public static function thumbJPG120($name){
        return str_replace('.jpg','_120x120.jpg', $name);
    }

    public static function bajaEstado(int $estado){
        $estado = (int) $estado;
        if($estado === 0){
            $estadoLabel = "Captura";
            $estadoBadge = "info";
        }else if($estado === 1){
            $estadoLabel = "Pendiente";
            $estadoBadge = "warning";
        }else if($estado === 2){
            $estadoLabel = "Corregir";
            $estadoBadge = "danger";
        }else if($estado === 3){
            $estadoLabel = "Aprobada";
            $estadoBadge = "success";
        }else if($estado === 4){
            $estadoLabel = "Cancelada";
            $estadoBadge = "danger";
        }

        $obj = new \stdClass();
        $obj->label = $estadoLabel;
        $obj->badge = $estadoBadge;
        return $obj;
    }

    public static function inventarioEstado(int $estado){
        $estado = (int) $estado;
        if($estado === 0){
            $estadoLabel = "Carga inicial";
            $estadoBadge = "secondary";
        }else if($estado === 1){
            $estadoLabel = "Captura";
            $estadoBadge = "info";
        }else if($estado === 2){
            $estadoLabel = "Pendiente";
            $estadoBadge = "warning";
        }else if($estado === 3){
            $estadoLabel = "Corregir";
            $estadoBadge = "danger";
        }else if($estado === 4){
            $estadoLabel = "Aprobado";
            $estadoBadge = "success";
        }else if($estado === 5){
            $estadoLabel = "Aprobado (Inicial)";
            $estadoBadge = "success";
        }else{
            $estadoLabel = "Error";
            $estadoBadge = "secondary";
        }

        $obj = new \stdClass();
        $obj->label = $estadoLabel;
        $obj->badge = $estadoBadge;
        return $obj;
    }

    public static function traspasoEstado(int $estado){
        $estado = intval($estado);
        if($estado === 0){
            $estadoLabel = "Captura";
            $estadoBadge = "info";
        }else if($estado === 1){
            $estadoLabel = "Pendiente";
            $estadoBadge = "warning";
        }else if($estado === 2){
            $estadoLabel = "Corregir";
            $estadoBadge = "danger";
        }else if($estado === 3){
            $estadoLabel = "Autorizado";
            $estadoBadge = "success";
        }else if($estado === 4){
            $estadoLabel = "Cancelado";
            $estadoBadge = "danger";
        }else{
            $estadoLabel = 'No definido';
            $estadoBadge = 'secondary';
        }

        return (object) [
            'label' => $estadoLabel,
            'badge' => $estadoBadge
        ];
    }

    /**
     * Retorna un arreglo con los subalmacenes excluidos en el sistema de departamentos
     */
    public static function subalmacenesExcluidos($asString = false){
        $user = Auth::user();
        $admin = self::tienePermiso('super_admin');;

        if($admin){
            $ex = [];
        }else{
            $ex = ['008', '010'];
        }

        return $ex;
    }

    public static function modo(){
        if(config('app.interface') == 'local')
            return 'local';
        return 'no local';
    }

    /**
     * Retorna un objeto con los nombres de las tablas dependiendo del modo en que se encuentre.
     * 
     * Tablas innsist o locales.
     */
    public static function tablas(){
        $modo = self::modo();
        if($modo === 'local'){
            $tablas = [
                'articulos' => 'inv_articulos',
                'articulos_sub' => 'inv_articulos_detalle',
                'familias' => 'inv_familias',
                'familia_subfamilia' => 'inv_familia_subfamilia',
                'subfamilias' => 'inv_subfamilias',
                'almacenes' => 'inv_almacenes',
                'subalmacenes' => 'inv_subalmacenes',
            ];
        }else{
            $tablas = [
                'articulos' => 'BoInvArticulos',
                'articulos_sub' => 'BoInvArticulosSub',
                'familias' => 'BoInvFamilias',
                'subfamilias' => 'BoInvSubFamilias',
                'familia_subfamilia' => 'BoInvLinkSubFam',
                'almacenes' => 'BoInvAlmacenes',
                'subalmacenes' => 'BoInvSubAlmacenes',
            ];
        }

        return (object) $tablas;
    }

    /**
     * Hace un merge de todos los errores encontrados en el validator
     * y los retorna en formato JSON
     */
    public static function validatorErrorsToJson($validator){
        $errors = $validator->errors()->getMessages();
        $errs = [];
        foreach($errors as $key => $messages){
            $errs = array_merge($errs, $messages);
        };

        return self::json([
            'error' => 'Error',
            'inputs' => true,
            'messages' => $errs
        ]);
    }

    /**
     * Retorna la excepción en formato JSON y los datos del usuario autenticado.
     */
    public static function exceptionResponse(\Exception $e)
    {
        $u = Auth::user();
        Log::error($e->getMessage() . $e->getTraceAsString(), ['uid' => $u->id, 'email' => $u->email]);

        return self::json([
            'error' => 'Oops... ocurrio un error: ' . $e->getMessage()
        ]);
    }

    /**
     * Retorna el valor ingresado con ceros a la izquierda.
     * 
     * @param int|string $val string/numero al que se le agregaran ceros a la izquierda
     * @param int $n numero de ceros a la izquierda
     * @return string
     */
    public static function cerosDerecha($val, int $n = 8){
        $format = "%0{$n}d";
        $return = sprintf($format, $val);

        return $return;
    }

    /**
     * Comprueba si el usuario tiene el permiso que se esta solicitando. 
     * 
     * Primero revisa si el permiso lo tiene asignado como global, si no lo tiene 
     * comprueba si lo tiene asignado en un empresa.
     * 
     * @param string $permiso Nombre del permiso a validar
     * @param int $empresa Id de la empresa en donde tiene el permiso
     * 
     * @return boolean
     */
    public static function tienePermiso($permiso, $empresa = 0, $guard = null, $userId = null){
        return Users::tienePermiso($permiso, $empresa, $userId);
    }

    /**
     * Comprueba si el usuario tiene la firma especificada.
     * 
     * Valida primero que el usuario tenga la firma de forma global (empresa = null),
     * despues valida por empresa
     *
     * @param string $firma
     * @param int $empresa
     * @param string $guard
     */
    public static function tieneFirma($firma, $empresa = null, $guard = null)
    {
        $user = auth($guard)->user();

        $firmas = $user->firmas();
        $firmas = $firmas->where('nombre', $firma);

        // GLBOAL
        if($firmas->wherePivot('empresa_id', null)->exist()){
            return true;
        // POR EMPRESA
        } else if ($firmas->where('empresa_id', $empresa)->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Comprueba si el usuario tiene ALGUNO de los permisos que se esta solicitando. 
     * 
     * Primero revisa si el permiso lo tiene asignado como global, si no lo tiene 
     * comprueba si lo tiene asignado en un empresa.
     * 
     * @param array $permisos Grupo de permisos a validar
     * @param int $empresa Id de la empresa en donde tiene el permiso
     * 
     * @return boolean
     */
    public static function tieneAlgunPermiso(array $permisos, $empresa = 0){
        return Users::tieneAlgunPermiso($permisos, $empresa);
    }

    /**
     * Comprueba si el usuario tiene TODOS de los permisos que se esta solicitando. 
     * 
     * Primero revisa si el permiso lo tiene asignado como global, si no lo tiene 
     * comprueba si lo tiene asignado en un empresa.
     * 
     * @param array $permisos Grupo de permisos a validar
     * @param int $empresa Id de la empresa en donde tiene el permiso
     * 
     * @return boolean
     */
    public static function tienePermisos(array $permisos, $empresa = 0){
        $user = auth()->user();
        if($user->permisoEnEmpresa($permisos, 0, true)){
            return true;
        }

        // si se esta solicitando la empresa 0 no hay necesidad de buscarlo por empresas
        if($empresa == 0){
            return false;
        }

        if($user->permisoEnEmpresa($permisos, $empresa, true)){
            return true;
        }

        return false;
    }

    /**
     * Retorna los ids de las empresas donde tenga el permiso solicitado.
     * 
     * @param string $permiso Permiso a validar
     * @param string[] $permisos No se utiliza!
     * @param string $guard No se utiliza!
     * @param boolean $count Si es `true`, se retorna el numero de empresas donde tiene el permiso especificado
     * @param \App\User|int $userId Id o modelo del usuario
     * 
     * @return array
     */
    public static function empresasPorPermiso($permiso, $permisos = null, $guard = null, $count = false, $userId = null){
        $perms = Users::empresasPorPermiso($permiso, null, $userId);
        if ($count) {
            return count($perms);
        }
        return $perms;
    }

    /**
     * Recibe un arreglo de permisos y retorna solo aquellos que el usuario tenga.
     * 
     * Útil cuando se requiere el nombre del permiso para validaciones.
     * Ejem:
     *  Una vista donde se se validen multiples permisos. 
     * 
     * $permisos = Utils::permisosValidos(['permiso_1', 'permiso_2'], $empresa);
     * 
     * if(in_array('permiso_1')) return true;
     * 
     * @param array $permisos Grupo de permisos a validar
     * @param int $empresa Id de la empresa en donde tiene el permiso
     * 
     * @return array
     */
    public static function permisosValidos(array $permisos, $empresa = 0){
        $user = auth()->user();
        $_permisos = [];

        $_permisos = array_merge($_permisos, $user->permisoEnEmpresaToArray($permisos, 0));
        if($empresa == 0) return collect($_permisos)->unique()->toArray();

        $_permisos = array_merge($_permisos, $user->permisoEnEmpresaToArray($permisos, $empresa));

        return collect($_permisos)->unique()->toArray();
    }


    /**
     * Genera el titulo para las ordenes de compras y facturas.
     * 
     * Dependiendo de la URL y el permiso que se maneje se mostrara un titulo.
     * 
     * Titulos: Pendientes, Programadas para Pago y Autorizadas
     * 
     * @param string $permisoStr
     * @return string
     */
    public static function tituloOdcFactura($permisoStr){
        $title = '';
        $currentUrl = str_replace('public/facturas/', '', url()->current());
        $currentUrl = str_replace('public/odc/', '', $currentUrl);
        $programadas =  strpos($currentUrl, 'programadas/') !== false;
        $facturas =  strpos($currentUrl, 'facturas/') !== false;
        $odc =  strpos($currentUrl, 'ordenes/') !== false;
        if($facturas || $odc){
            if($permisoStr == 'pagos_fac_pagar_facturas' || $permisoStr == 'pagos_odc_pagar_odc'){
                $title = 'Programadas para Pago';
            }else if($permisoStr == 'pagos_fac_autorizar_facturas' || $permisoStr == 'pagos_odc_autorizar_odc'){
                $title = 'Programadas para Pago';
            }else if($permisoStr == 'pagos_fac_solicitar_pago_facturas' || $permisoStr == 'pagos_odc_solicita_pago_odc'){
                $title = 'Pendientes';
            }
        }else if($programadas){
            if($permisoStr == 'pagos_fac_pagar_facturas'){
                $title = 'Programadas para Pago';
            }else if($permisoStr == 'pagos_fac_autorizar_facturas' || $permisoStr == 'pagos_odc_autorizar_odc'){
                $title = 'Autorizadas';
            }else if($permisoStr == 'pagos_fac_solicitar_pago_facturas' || $permisoStr == 'pagos_odc_solicita_pago_odc'){
                $title = 'Programadas para Pago';
            }
        }else{
            if($permisoStr == 'pagos_fac_pagar_facturas' || $permisoStr == 'pagos_odc_pagar_odc'){
                $title = 'Programadas para Pago';
            }else if($permisoStr == 'pagos_fac_autorizar_facturas' || $permisoStr == 'pagos_odc_autorizar_odc'){
                $title = 'Programadas para Pago';
            }else if($permisoStr == 'pagos_fac_solicitar_pago_facturas' || $permisoStr == 'pagos_odc_solicita_pago_odc'){
                $title = 'Pendientes';
            }
        }

        return $title;
    }

    public static function formatDateTime($datetime, $format = '%d/%b/%Y'){

        if(is_numeric($format)){
            $formatOpt = (int)$format;
            switch($formatOpt){
                case 1: $format = '%d/%b/%Y %T'; break;
                case 2: $format = '%Y-%m-%d %T'; break;
                case 3: $format = '%Y-%m-%d'; break;
                default: $format = '%d/%b/%Y';
            }
        }


        if(!$datetime) return null;
        try{
            return Carbon::parse($datetime)->formatLocalized($format);
        }catch(\Exception $e){ }

        return $datetime;
    }

    
    public static function minutesToDays($minutes, $formatted = false)
    {
        if($minutes == -1) return 'Ilimitado';
        if($minutes == 0) return 'Expirado';

        $dias = ($minutes / 60) / 24;

        if($minutes < 60){
            if($formatted) {
                return $minutes . ' min';
            }
            return $minutes;
        }

        if($dias < 1){
            $horas = (int) ($minutes / 60);
            if($formatted){
                return $horas . ' hrs';
            }
            return $horas;
        }

        $dias = (int) $dias;
        if($formatted){
            return $dias . ' Dias';
        }

        return $dias;
    }

    /**
     * Helper para almacenar archivos desde el request
     *
     * @param UploadedFile|null $file Archivo desde el request
     * @param string|null $name Especificar nombre del archivo para no utilizar el nombre desde el cliente
     * @param string|null $disk Disco donde se almacenara
     * @param string|null $path Directorio dentro del disco
     * @param boolean $unique Indica si el nombre del archivo deberá ser único (agrega un aleatorio)
     * 
     * @return string
     */
    public static function uploadFile($file, $name = null, $disk = 'public', $path = '/', $unique = false)
    {
        if(!$file) return null;
        
        // NOMBRE PERSONALIZADO
        if($name){
            $fileName = $name;
        }else {
            // NOMBRE DESDE EL CLIENTE
            $fileName = $file->getClientOriginalName();
        }

        // CONCATENAR STRING ALEATORIO PARA GENERAR NOMBRE UNICO
        if($unique){
            $fileName = str_random(5). '_' . $fileName;
        }

        // REMOVER CARACTERES ESPECIALES Y LIMITAR NOMBRE A 250 CARACTERES
        $fileName = Utils::stringWithoutSpecialCharacters($fileName, true, true);

        // SI EL DIRECTORIO ESPECIFICADO NO EXISTE, CREARLO
        $storage = Storage::disk($disk);
        if(!$storage->exists($path)){
            $storage->makeDirectory($path);
        }

        $file->storeAs($path, $fileName, ['disk' => $disk]);

        return $fileName;
    }


    public static function strip_specific_tags ($str, $tags) {
        if (!is_array($tags)) $tags = [$tags]; 
        
        foreach ($tags as $tag) {
            $_str = preg_replace('/<\/' . $tag . '>/i', '', $str);
            if ($_str != $str) {
            $str = preg_replace('/<' . $tag . '[^>]*>/i', '', $_str);
            }
        }
        return $str;
    }

    /**
     * Retorna el valor que se encuentre en el indice especificado.
     *
     * @param array $arr Arreglo donde se buscara el indice
     * 
     * @param string|int $key Indice a buscar
     */
    public static function arrGetValue(array $arr, $key, $defaultValue = null)
    {
        if (!is_array($arr)) {
            throw new \Exception('arrGetValue: El parámetro "$arr" debe ser un arreglo');
        }

        if (array_key_exists($key, $arr)) {
            return $arr[$key];
        }

        if ($defaultValue) {
            return $defaultValue;
        }

        return null;
    }

    /**
     * Se encarga de remplazar las letras con acentos y otros caracteres especiales
     *
     * @param string $str
     * 
     * @return string
     */
    public static function clearString($str)
    {
        $utf8 = array(
            '/[áàâãªä]/u'   =>   'a',
            '/[ÁÀÂÃÄ]/u'    =>   'A',
            '/[ÍÌÎÏ]/u'     =>   'I',
            '/[íìîï]/u'     =>   'i',
            '/[éèêë]/u'     =>   'e',
            '/[ÉÈÊË]/u'     =>   'E',
            '/[óòôõºö]/u'   =>   'o',
            '/[ÓÒÔÕÖ]/u'    =>   'O',
            '/[úùûü]/u'     =>   'u',
            '/[ÚÙÛÜ]/u'     =>   'U',
            '/ç/'           =>   'c',
            '/Ç/'           =>   'C',
            '/ñ/'           =>   'n',
            '/Ñ/'           =>   'N',
            '/–/'           =>   '-',
            '/[’‘‹›‚]/u'    =>   ' ',
            '/[“”«»„]/u'    =>   ' ',
            '/ /'           =>   ' ',
        );
        return preg_replace(array_keys($utf8), array_values($utf8), $str);
    }

    /**
     * Le da formato a un numero.
     * 
     * @param int|string $number Numero al que se le dará formato
     * @param string $point Carácter que se usara como separador de decimales
     * @param string $thousandsDelim Delimitador que se usara para los millares
     * 
     * @return string
     */
    public static function fmtNumber($number, $decimals  = 2,  $point = '.', $thousandsDelim = ',')
    {
        if (!is_numeric($number)) return $number;
        $nFmted = number_format($number, $decimals, $point, $thousandsDelim);
        
        return $nFmted;
    }

    /**
     * Retorna los permisos que funcionan como menus
     * 
     * @return \Illuminate\Support\Collection
     */
    public static function menus()
    {
        $menu = Sistema::where('sistema', 'menus')->first();
        if ($menu) {
            $permisos = $menu->permisos()->orderBy('permiso')->get();
        } else {
            $permisos = collect();
        };

        return $permisos;
    }
}