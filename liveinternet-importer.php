<?php

/*
Plugin Name: Liveinternet Importer
Plugin URI: http://wordpress.org/extend/plugins/liveinternet-importer/
Description: Import posts and comments from Liveinternet.
Author: Seyfer (recode from dmpink.ru)
Author URI: http://seyferseed.div-portal.ru/
Version: 2012.3
Stable tag: 2012.3
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

//параметр, который мы можем установить в wp-config.php файле. позволяет или запрещает производить импорт.
if ( !defined('WP_LOAD_IMPORTERS') )
	return;

// Load Importer API. базовый файл с классом импорта
require_once ABSPATH . 'wp-admin/includes/import.php';

// если вдруг все же не загрузился базовый класс 
if ( !class_exists( 'WP_Importer' ) ) {
	//загружаем класс
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

// только если базовый класс загружен продолжаем
if ( class_exists( 'WP_Importer' ) ) {
//объявляем свой класс, который расширяет базовый
class liru_Import extends WP_Importer {

//переменная будет хранить данные текущего загруженного файла
var $file;

//выводит заголовок
function header() {
	echo '<div class="wrap">';
	echo '<h2>'.__('Import LiveInternet.ru').'</h2>';
}
//выводит футер
function footer() {
	echo '</div>';
}

//ф-я декодирования хтмл обратная к htmlentities()
//get_html_translation_table возвращает таблицу, по которой выполняют декодирование 
//htmlspecialchars() and htmlentities()
//array_flip меняет местами значения и ключи в массиве
//strtr с двумя аргументами возвращает строку, где вхождения символов заменены на 
//найденные в массиве по ключам
function unhtmlentities($string) { // From php.net for < 4.3 compat
	$trans_tbl = get_html_translation_table(HTML_ENTITIES);
	$trans_tbl = array_flip($trans_tbl);
	return strtr($string, $trans_tbl);
}

//ф-я вызывается на первом шаге работы плагина
function greet() {
	//выводим информацию
	echo '<div class="narrow">';
	echo '<p>'.__('Howdy! Upload your LiveInternet.ru XML export file and we&#8217;ll import the posts into this blog.').'</p>';
	echo '<p>'.__('Choose a LiveInternet.ru XML file to upload, then click Upload file and import.').'</p>';
	//выводится форма, которая принимает для загрузки файл
	//файл загружается в папку заданную для загрузки в настройках WordPress
	//как видно в параметрах тут мы переходим на шаг 2
	wp_import_upload_form("admin.php?import=liru&amp;step=1");
	echo '</div>';
}

//тут происходит вся работа и все самое интересное
function import_posts() {
	//$wpdb объявляем экземпляр класса для доступа к базе WP. 
	//будем использовать ф-ю для очистки строки перед вставкой в базу
	//$current_user экземпляр для работы с пользователем. будем получать автора
	global $wpdb, $current_user;
	//на всякий случай отключаем
	set_magic_quotes_runtime(0);
	//получаем данные из текущего файла в массив
	$importdata = file($this->file); // Read the file into an array
	//превращаем массив в строку
	$importdata = implode('', $importdata); // squish it
	//удаляем переходы на новую строку
	$importdata = str_replace(array ("\r\n", "\r"), "\n", $importdata);
	//находим все вхождения <item>. это и есть все наши посты
	preg_match_all('|<item>(.*?)</item>|is', $importdata, $posts);
	//берем только массив 1
	$posts = $posts[1];
	unset($importdata);
	//начинаем вывод служебной информации и импорт в базу
	echo '<ol>';
	//для каждого <item> парсим контент
	foreach ($posts as $post) {
		//получаем заголовок
	  preg_match('|<title>(.*?)</title>|is', $post, $post_title);
	  //В XML документах фрагмент, помещённый внутрь CDATA,— это часть содержания элемента, 
	  //помеченная для парсера, что она содержит только символьные данные, не разметку.
	  //Удаляем
	  $post_title = str_replace(array ('<![CDATA[', ']]>'), '', trim($post_title[1]));
	  //делаем строку безопасной. вдруг у вас был пост про DROP TABLE :)
	  $post_title = $wpdb->escape(trim($post_title));
		if ( empty($post_title) ) {
			//если название пустое то пусть оно будет ссылкой
			preg_match('|<link>(.*?)</link>|is', $post, $post_title);
			$post_title = $wpdb->escape(trim($post_title[1]));
		}
		//перекодируем в utf-8
		$post_title = iconv("windows-1251","utf-8",$post_title);
		//получаем дату
		preg_match('|<pubDate>(.*?)</pubDate>|is', $post, $post_date);
		$post_date = $post_date[1];
		$post_date = str_replace(array ('<![CDATA[', ']]>'), '', trim($post_date));
		//превращаем строку в формат Unix timestamp
		$post_date = strtotime($post_date);
		//и формируем формат datetime для записи в базу
		$post_date = date('Y-m-d H:i:s', $post_date);
		//получаем короткое описание
		preg_match('|<description>(.*?)</description>|is', $post, $post_content);
		$post_content = str_replace(array ('<![CDATA[', ']]>'), '', trim($post_content[1]));
		//теги должны быть тегами
		$post_content = $this->unhtmlentities($post_content);
		$post_content = iconv("windows-1251","utf-8",$post_content);
		// Clean up content приводим теги в порядок
		//заменяем большие буквы в теге на маленькие
		$post_content = preg_replace('|<(/?[A-Z]+)|e', "'<' . strtolower('$1')", $post_content);
		//делаем правильные теги
		$post_content = str_replace('<br>', '<br />', $post_content);
		$post_content = str_replace('<hr>', '<hr />', $post_content);
		$post_content = $wpdb->escape($post_content);
		//получаем ID текущего пользователя
		$post_author = $current_user->ID;
		//статус - опубликовано
		$post_status = 'publish';
		//теперь будем парсить теги. тут очень хитрый кусок кода
		//теги записаны в хмл между <category>(.*?)</category> в новой строке каждый
		$offset = 0;
    $match_count = 0;	
    $tags_input = '';
    while(preg_match('|<category>(.*?)</category>|is', $post, $matches, PREG_OFFSET_CAPTURE, $offset))
    {
		//счетчик найденных
        $match_count++;
		//начальная позиция найденной строки
        $match_start = $matches[0][1];
		//длина найденной строки
        $match_length = strlen($matches[0][0]);
        $matches[0][0]=str_replace(array ('<![CDATA[', ']]>'), '', trim($matches[0][0]));
		//добавляем тег в строку
        $tags_input = $tags_input.$matches[0][0].',';
		//вычисляем сдвиг где искать следующую строку
        $offset = $match_start + $match_length;
    }
    $tags_input = iconv("windows-1251","utf-8",$tags_input);
		echo '<li>';
		//выполняем наконец-то вставку в базу
		if ($post_id = post_exists($post_title, $post_content, $post_date)) {
			//если такой пост уже есть, то не делаем копию. post_exists() стандартная ф-я
			printf(__('Post <em>%s</em> already exists.'), stripslashes($post_title));
		} else {
			printf(__('Importing post <em>%s</em>...'), stripslashes($post_title));
			//создаем массив содержащий ключи (имя переменной) и значения переменной.
			$postdata = compact('post_author', 'post_date', 'post_content', 'post_title', 'post_status', 'tags_input');
			//ф-я добавления поста в базу. возвращает Ид добавленного поста.
			$post_id = wp_insert_post($postdata);
			if ( is_wp_error( $post_id ) )
				return $post_id;
			if (!$post_id) {
				_e("Couldn't get post ID");
				echo '</li>';
				break;
			}
		}
	}
}

//ф-я управляет ходом импорта, этакий контроллер
function import() {
	//wp_import_handle_upload return: Uploaded file's details on success, error message on failure
	$file = wp_import_handle_upload();
	//если ошибка то выводим и выходим
	if ( isset($file['error']) ) {
		echo $file['error'];
		return;
	}
	//принимаем файл
	$this->file = $file['file'];
	//вот она, вот она функуция импорта моей мечты :)
	//производим собственно импорт. возвратит ошибку или ничего если успешно
	$result = $this->import_posts();
	//если ошибка то возвращаем
	if ( is_wp_error( $result ) )
		return $result;
	//wp_import_cleanup Removes attachment based on ID. т.е. удаляем файл
	wp_import_cleanup($file['id']);
	//создаем собственный хук. такой же создается в базовом классе
	//do_action('import_done', 'wordpress'); для базового импорта
	do_action('import_done', 'LiveInternet.ru');
	echo '<h3>';
	//выводим после импорта сообщение и ссылку
	printf(__('All done. <a href="%s">Have fun!</a>'), get_option('home'));
	echo '</h3>';
}

//первая ф-я которая срабатывает. 
function dispatch() {
	//определяем шаг выполнения и делаем что-то
	if (empty ($_GET['step']))
		$step = 0;
	else
		$step = (int) $_GET['step'];
	//в любом случае выводим заголовок
  	$this->header();
	switch ($step) {
		case 0 :
			//в этом случае выводим приветствие и форму
			$this->greet();
			break;
		case 1 :
			//форма загружена, файл указан.
			//выполняем проверку, что загрузка была с админской панели
			check_admin_referer('import-upload');
			//вызываем функцию, которая управляет импортом.
			//в $result может быть ошибка, если что-то пошло не так или пусто
			$result = $this->import();
			//если в резулт ошибка, выводим сообщение
			if ( is_wp_error( $result ) )
				echo $result->get_error_message();
			break;
	}
	//выводим футер в любом случае
	$this->footer();
}

//Пустой конструктор. Просто чтобы был.
function liru_Import() {
// Nothing.
}

}
}

//перехватываем хуком init и вызываем свою функцию. 
add_action( 'init', 'liru_Import' );

// создаем экземпляр класса
$liveinternetru_import = new liru_Import();

//при первой установке нужно зарегистрировать плагин в системе.
//register_importer( $id, $name, $description, $callback )
// $id - уникальный идентификатор нашего импортера
// $name имя для отображения
// $description короткое описание (можно интернационализировать!)
// $callback самое главное тут - какую ф-ю вызывать в первую очередь
register_importer('liru', __('LiveInternet.ru'), __('Import posts from a LiveInternet.ru XML export file.'), array ($liveinternetru_import, 'dispatch'));
?>