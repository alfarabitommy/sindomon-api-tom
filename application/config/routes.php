<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------------
| This file lets you re-map URI requests to specific controller functions.
|
| Typically there is a one-to-one relationship between a URL string
| and its corresponding controller class/method. The segments in a
| URL normally follow this pattern:
|
|	example.com/class/method/id/
|
| In some instances, however, you may want to remap this relationship
| so that a different class/function is called than the one
| corresponding to the URL.
|
| Please see the user guide for complete details:
|
|	https://codeigniter.com/userguide3/general/routing.html
|
| -------------------------------------------------------------------------
| RESERVED ROUTES
| -------------------------------------------------------------------------
|
| There are three reserved routes:
|
|	$route['default_controller'] = 'welcome';
|
| This route indicates which controller class should be loaded if the
| URI contains no data. In the above example, the "welcome" class
| would be loaded.
|
|	$route['404_override'] = 'errors/page_missing';
|
| This route will tell the Router which controller/method to use if those
| provided in the URL cannot be matched to a valid route.
|
|	$route['translate_uri_dashes'] = FALSE;
|
| This is not exactly a route, but allows you to automatically route
| controller and method names that contain dashes. '-' isn't a valid
| class or method name character, so it requires translation.
| When you set this option to TRUE, it will replace ALL dashes in the
| controller and method URI segments.
|
| Examples:	my-controller/index	-> my_controller/index
|		my-controller/my-method	-> my_controller/my_method
*/
// Login Api
$route['default_controller'] = 'welcome';
$route['api/v1/auth/insert'] = 'auth/insert_user';
$route['api/v1/auth/login'] = 'auth/login';
$route['api/v1/user'] = 'auth/all';
//Roles
$route['api/v1/role']['get']    = 'role/get';
$route['api/v1/role']['post']   = 'role/post';
$route['api/v1/role']['put']    = 'role/put';
$route['api/v1/role']['delete'] = 'role/delete';
//Profile
$route['api/v1/profile']['get']    = 'profile/get';
//polda
$route['api/v1/polda']['get']    = 'polda/get';
// Pengaduan
$route['api/v1/pengaduan/tiket']['GET'] = 'pengaduan/tiket';
$route['api/v1/pengaduan/tiket/(:num)/status']['PATCH'] = 'pengaduan/ubah_status/$1';

$route['api/v1/knowledge/dokumen']['GET'] = 'knowledge/dokumen';
$route['api/v1/kamtibmas/laporan']['POST'] = 'kamtibmas/laporan';
$route['api/v1/dms/surat']['POST'] = 'dms/surat';
$route['api/v1/dms/surat']['GET'] = 'dms/inbox_outbox';
$route['api/v1/dms/surat/(:any)/download']['GET'] = 'dms/download/$1';
$route['api/v1/dms/surat/(:any)/read']['PATCH'] = 'dms/read/$1';
$route['api/v1/logistik/senjata']['POST'] = 'logistik/senjata_post';
$route['api/v1/logistik/amunisi']['POST'] = 'logistik/amunisi_post';
$route['api/v1/logistik/amunisi']['GET'] = 'logistik/amunisi_get';
$route['api/v1/logistik/satwa']['POST'] = 'logistik/satwa_post';
// SDM
$route['api/v1/sdm/org-tree']['GET'] = 'sdm/org_tree_get';
$route['api/v1/sdm/personil']['GET'] = 'sdm/personil_get';
$route['api/v1/sdm/personil']['POST'] = 'sdm/personil_post';
$route['api/v1/sdm/personil/(:any)']['PUT'] = 'sdm/personil_put/$1';
$route['api/v1/sdm/hukum']['POST'] = 'sdm/hukum_post';
// Master
$route['api/v1/master/wilayah']['GET'] = 'master/wilayah_get';
$route['api/v1/master/polres']['POST'] = 'master/polres_post';
$route['api/v1/master/polres/(:num)']['PUT'] = 'master/polres_put/$1';
$route['api/v1/master/polres/(:num)']['DELETE'] = 'master/polres_delete/$1';
$route['404_override'] = '';
$route['translate_uri_dashes'] = FALSE;
