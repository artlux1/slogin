<?php
/**
 * Social Login Avatar
 *
 * @version    1.5
 * @author        Andrew Zahalski
 * @copyright    © 2013. All rights reserved.
 * @license    GNU/GPL v.3 or later.
 */

// No direct access
defined('_JEXEC') or die;

class plgSlogin_integrationSlogin_avatar extends JPlugin
{


    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        JPlugin::loadLanguage('plg_slogin_integration_slogin_avatar', JPATH_ADMINISTRATOR);
    }


    public function onAfterSloginLoginUser($instance, $provider, $info)
    {

        if (!$provider) return;

        $origimage = $new_image = '';
        //Параметры изображений для noimage, если социальная сеть не отдает данные о том что аватар не загружен
        $arNoimage['vkontakte'] = 'camera_b.gif';
        $arNoimage['odnoklassniki'] = 'stub_50x50.gif';

        //максимальная ширина и высота для генерации изображения
        $max_h = $this->params->get('imgparam', 50);
        $max_w = $this->params->get('imgparam', 50);

        // $data Объект с параметрами для подготовки к записи в БД
        $data = new stdclass();
        $data->user_provider = $provider;
        $data->up = 1;
        $data->user_id = $instance->id;
        $data->user_photo = '';

        switch ($provider) {
            //google
            case 'google':
                $max_h = false; //google foto fix
                if ($info->picture) {
                    $origimage = $info->picture;
                    $new_image = $provider . '_' . $info->id . '.jpg';
                }
                break;
            //linkedin
            case 'linkedin':
                if ($info->pictureUrl) {
                    $origimage = $info->pictureUrl;
                    $new_image = $provider . '_' . $info->id . '.jpg';
                }
                break;
            //vkontakte
            case 'vkontakte':
                $ResponseUrl = 'https://api.vk.com/method/getProfiles?uid=' . $info->uid . '&fields=photo_medium';
                $request = json_decode(plgSlogin_integrationSlogin_avatar::openHttp($ResponseUrl))->response[0];
                if (!empty($request->error)) {
                    return;
                }
                if (substr($request->photo_medium, -12, 10000) != $arNoimage['vkontakte']) {
                    $origimage = $request->photo_medium;
                    $new_image = $provider . '_' . $info->uid . '.jpg';
                }
                break;
            //facebook
            case 'facebook':
                $foto_url = 'http://graph.facebook.com/' . $info->id . '/picture?type=square&redirect=false';
                $request_foto = json_decode(plgSlogin_integrationSlogin_avatar::openHttp($foto_url));

                if (!empty($request_foto->error)) {
                    return;
                }

                if ($request_foto->data->is_silhouette === false) { //если аватар загружен
                    if ($request_foto->data->url) {
                        $origimage = $request_foto->data->url;
                    } else {
                        $origimage = false;
                    }
                    $new_image = $provider . '_' . $info->id . '.jpg';
                }
                break;
            //twitter
            case 'twitter':
                if ($info->default_profile_image != 1) {
                    $origimage = $info->profile_image_url;
                    $new_image = $provider . '_' . $info->id . '.jpg';
                }
                break;
            //odnoklassniki
            case 'odnoklassniki':
                if (substr($info->pic_1, -14, 10000) != $arNoimage['odnoklassniki']) {
                    $origimage = $info->pic_1;
                    $new_image = $provider . '_' . $info->uid . '.jpg';
                }
                break;
            //mail
            case 'mail':
                if ($info->has_pic == '1') {
                    $origimage = $info->pic_50;
                    $new_image = $provider . '_' . $info->uid . '.jpg';
                }
                break;
            //yandex не дает аватарки, либо нужны жесткие права доступа
            case 'yandex':
                return;
                break;
            //если не поддерживается провайдер то фото нет
            default:
                return;
                break;
        }

        if ($this->getStatusUpdate($provider, $data->user_id, $origimage, $new_image, $max_w, $max_h)) {
            $data->user_photo = $new_image;
            plgSlogin_integrationSlogin_avatar::addPhotoSql($data);
        }
    }


    /**
     * проверим стоит ли писать аватар в базу, также обновляем главный аватар
     * @return boolean
     */
    private function getStatusUpdate($provider, $userid, $file_input, $file_output, $w_o, $h_o)
    {
        $statfoto = plgSlogin_integrationSlogin_avatar::resize($file_input, $file_output, $w_o, $h_o);

        if ($statfoto == 'up') {
            plgSlogin_integrationSlogin_avatar::updateMainAvatar($provider, $userid);
            return true;
        } else { //ok, false
            return false;
        }

    }


    /**
     * Метод для генерации изображения
     * @param string $url    УРЛ изображения
     * @param string $file_output    Название изображения для сохранения
     * @param int $w_o, $h_o    Максимальные ширина и высота генерируемого изображения
     * @return string    Результат выполнения false - изображения нет, up - успешно записали и нужно обновиться, ok - изображение существует и не требует модификации
     */
    private function resize($file_input, $file_output, $w_o, $h_o, $percent = false)
    {

        //Если источник не указан
        if (!$file_input) return false;

        $time = time();

        //папка для работы с изображением и качество сжатия
        $rootfolder = $this->params->get('rootfolder', 'images/avatar');
        $imgcr = $this->params->get('imgcr', 80);

        //Если файл существует и время замены не подошло возвращаем статус 'ok'
        if (is_file(JPATH_BASE . '/' . $rootfolder . '/' . $file_output)) {
            if (filemtime(JPATH_BASE . '/' . $rootfolder . '/' . $file_output) > ($time - $this->params->get('updatetime', 86400))) {
                return 'ok';
            }
        }

        //генерация превью
        $file_input = plgSlogin_integrationSlogin_avatar::openHttp($file_input);
        if ($file_input) {
            //если папка для складирования аватаров не существует создаем ее
            if (!JFolder::exists(JPATH_BASE . '/' . $rootfolder)) {
                JFolder::create(JPATH_BASE . '/' . $rootfolder);
                file_put_contents(JPATH_BASE . '/' . $rootfolder . '/index.html', '');
            }
            // Генерируем имя tmp-изображения
            $tmp_name = JPATH_BASE . '/' . $rootfolder . '/' . $file_output;

            // Сохраняем изображение
            file_put_contents($tmp_name, $file_input);

            // Очищаем память
            unset($file_input);

            //Работаем с временным изображением
            $file_input = $tmp_name;

            //Проверяем значение ширины и высоты
            list($w_i, $h_i, $type) = getimagesize($file_input);
            if (!$w_i || !$h_i) {
                return false;
            }

            $types = array('', 'gif', 'jpeg', 'png');
            $ext = $types[$type];
            if ($ext) {
                $func = 'imagecreatefrom' . $ext;
                $img = $func($file_input);
            } else {
                //если формат файла некоректный
                return false;
            }

            //ширина и высота для нового изображения
            if ($percent) {
                $w_o *= $w_i / 100;
                $h_o *= $h_i / 100;
            }
            if (!$h_o) $h_o = $w_o / ($w_i / $h_i);
            if (!$w_o) $w_o = $h_o / ($h_i / $w_i);

            //Генерируем аватар и записываем, возращаем статус 'up' если изображение успешно записали
            $img_o = imagecreatetruecolor($w_o, $h_o);
            imagecopyresampled($img_o, $img, 0, 0, 0, 0, $w_o, $h_o, $w_i, $h_i);

            //если jpg
            if ($type == 2) {
                imagejpeg($img_o, JPATH_BASE . '/' . $rootfolder . '/' . $file_output, $imgcr);
                return 'up';
            } else {
                //если другой формат то косяк
                //TODO Здесь какая-то ошибка, разобраться если будут проблемы.
                return false;
            }

        } else {
            return false;
        }
    }


    /**
     * Добавляем данные в БД
     * @param int $data->user_id    Ид пользователя
     * @param string $data->user_photo    Путь до изображения
     * @param int $data->up    1-обновить
     * @param string $data->user_provider    провайдер
     * @return boolean
     */
    private function addPhotoSql($data)
    {

        if ($data->up == 0 or !$data->user_photo or !$data->user_provider or !$data->user_id) return false;

        //Объект с данными для записи в БД
        $row = new stdclass();

        //Проверяем есть ли изображение в базе
        $db = JFactory::getDBO();
        $query = $db->getQuery(true);
        $query = "SELECT COUNT(*) FROM #__plg_slogin_avatar WHERE userid=" . $data->user_id . " AND provider='" . $data->user_provider . "'";
        $db->setQuery($query);
        $res = $db->loadResult();

        if ($res > 0) return true;

        //изображения нет в БД, формируем данные для записи в БД
        $row->id = NULL;
        $row->provider = $data->user_provider;
        $row->userid = $data->user_id;
        $row->main = 1;
        $row->photo_src = $data->user_photo;

        if (!$db->insertObject('#__plg_slogin_avatar', $row, 'id')) {
            echo $db->stderr();
            return false;
        }

    }


    /**
     * Метод для обновления приоритета аватара
     * @param string $provider    Провайдер
     * @param int $userid    Ид пользователя
     * @return
     */
    private function updateMainAvatar($provider, $userid)
    {

        //проверяем приоритет аватара
        $db = JFactory::getDBO();
        $query = $db->getQuery(true);
        $q = "SELECT COUNT(*) FROM #__plg_slogin_avatar WHERE provider='" . $provider . "' AND userid=" . $userid . " AND main=1";
        $res = $db->setQuery($q)->loadResult();
        if ($res) return false;

        //Обнуляем приоритеты
        $query->update('#__plg_slogin_avatar')->set('main=0')->where('userid=' . $userid);
        $db->setQuery($query);
        $db->query();

        //Устанавливаем новый приоритет
        $query->update('#__plg_slogin_avatar')->set('main=1')->where('userid=' . $userid)->where('provider="' . $provider . '"');
        $db->setQuery($query);
        $db->query();

        return true;

    }


    /**
     * Метод для отправки запросов
     * @param string $url    УРЛ
     * @param boolean $method    false - GET, true - POST
     * @param string $params    Параметры для POST запроса
     * @return string    Результат запроса
     */
    private function openHttp($url, $method = false, $params = null)
    {

        if (!function_exists('curl_init')) {
            die('ERROR: CURL library not found!');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, $method);
        if ($method == true && isset($params)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Length: ' . strlen($params),
            'Cache-Control: no-store, no-cache, must-revalidate',
            "Expires: " . date("r")
        ));
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        $result = curl_exec($ch);
        curl_close($ch);
        return $result;

    }

}
