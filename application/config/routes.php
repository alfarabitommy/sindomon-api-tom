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
$route['api/v1/user/(:num)']['PUT']    = 'auth/user_put/$1';
$route['api/v1/user/(:num)']['DELETE'] = 'auth/user_delete/$1';
//Roles
$route['api/v1/role']['get']    = 'role/get';
$route['api/v1/role']['post']   = 'role/post';
$route['api/v1/role']['put']    = 'role/put';
$route['api/v1/role']['delete'] = 'role/delete';
//Profile
$route['api/v1/profile']['get']    = 'profile/get';
// Polda (legacy — used by Flutter app)
$route['api/v1/polda']['GET']                      = 'polda/get';
$route['api/v1/polda']['OPTIONS']                  = 'polda/get';
// Master / Polda + Polres
$route['api/v1/master/polda']['GET']           = 'master/polda_get';
$route['api/v1/master/polda']['POST']          = 'master/polda_post';
$route['api/v1/master/polda/(:num)']['PUT']    = 'master/polda_put/$1';
$route['api/v1/master/polda/(:num)']['DELETE'] = 'master/polda_delete/$1';
$route['api/v1/master/polres']['GET']          = 'master/polres_get';
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
$route['api/v1/logistik/senjata/(:any)']['POST'] = 'logistik/senjata_post/$1';
$route['api/v1/logistik/senjata']['GET'] = 'logistik/senjata_get';
$route['api/v1/logistik/senjata/(:any)']['DELETE'] = 'logistik/senjata_delete/$1';
$route['api/v1/logistik/senjata']['OPTIONS'] = 'logistik/senjata_options';
$route['api/v1/logistik/senjata/(:any)']['OPTIONS'] = 'logistik/senjata_options';
$route['api/v1/logistik/amunisi']['POST'] = 'logistik/amunisi_post';
$route['api/v1/logistik/amunisi']['GET'] = 'logistik/amunisi_get';
$route['api/v1/logistik/amunisi']['OPTIONS'] = 'logistik/amunisi_options';
$route['api/v1/logistik/amunisi/(:any)']['OPTIONS'] = 'logistik/amunisi_options';
$route['api/v1/logistik/amunisi/(:any)']['PUT'] = 'logistik/amunisi_put/$1';
$route['api/v1/logistik/amunisi/(:any)']['DELETE'] = 'logistik/amunisi_delete/$1';
$route['api/v1/logistik/satwa']['POST'] = 'logistik/satwa_post';
$route['api/v1/logistik/satwa/(:any)']['POST'] = 'logistik/satwa_post/$1';
$route['api/v1/logistik/satwa']['GET'] = 'logistik/satwa_get';
$route['api/v1/logistik/satwa/(:any)']['DELETE'] = 'logistik/satwa_delete/$1';
$route['api/v1/logistik/satwa']['OPTIONS'] = 'logistik/satwa_options';
$route['api/v1/logistik/satwa/(:any)']['OPTIONS'] = 'logistik/satwa_options';
$route['api/v1/logistik/sarpras']['POST'] = 'logistik/sarpras_post';
$route['api/v1/logistik/sarpras/(:any)']['POST'] = 'logistik/sarpras_post/$1';
$route['api/v1/logistik/sarpras']['GET'] = 'logistik/sarpras_get';
$route['api/v1/logistik/sarpras/(:any)']['DELETE'] = 'logistik/sarpras_delete/$1';
$route['api/v1/logistik/sarpras']['OPTIONS'] = 'logistik/sarpras_options';
$route['api/v1/logistik/sarpras/(:any)']['OPTIONS'] = 'logistik/sarpras_options';
// SDM
$route['api/v1/sdm/org-tree']['GET'] = 'sdm/org_tree_get';
$route['api/v1/sdm/personil']['GET'] = 'sdm/personil_get';
$route['api/v1/sdm/personil']['POST'] = 'sdm/personil_post';
$route['api/v1/sdm/personil/(:any)']['PUT'] = 'sdm/personil_put/$1';
$route['api/v1/sdm/personil/(:any)']['DELETE'] = 'sdm/personil_delete/$1';
$route['api/v1/sdm/hukum']['POST'] = 'sdm/hukum_post';
// CORS preflight - SDM
$route['api/v1/sdm/org-tree']['OPTIONS']       = 'sdm/org_tree_get';
$route['api/v1/sdm/personil']['OPTIONS']       = 'sdm/personil_get';
$route['api/v1/sdm/personil/(:any)']['OPTIONS']= 'sdm/personil_get/$1';
$route['api/v1/sdm/hukum']['OPTIONS']          = 'sdm/hukum_post';
// Master
$route['api/v1/master/wilayah']['GET'] = 'master/wilayah_get';
$route['api/v1/master/polres']['POST'] = 'master/polres_post';
$route['api/v1/master/polres/(:num)']['PUT'] = 'master/polres_put/$1';
$route['api/v1/master/polres/(:num)']['DELETE'] = 'master/polres_delete/$1';
// Master / Kategori Senjata (Logistik master data)
$route['api/v1/master/kategori-senjata']['GET']           = 'master/kategori_senjata_get';
$route['api/v1/master/kategori-senjata']['OPTIONS']       = 'master/kategori_senjata_options';
$route['api/v1/master/kategori-senjata']['POST']          = 'master/kategori_senjata_post';
$route['api/v1/master/kategori-senjata/(:num)']['PUT']    = 'master/kategori_senjata_put/$1';
$route['api/v1/master/kategori-senjata/(:num)']['DELETE'] = 'master/kategori_senjata_delete/$1';
$route['api/v1/master/kategori-senjata/(:num)']['OPTIONS'] = 'master/kategori_senjata_options/$1';
// Master / Pangkat + Jabatan (SDM dropdown master data)
$route['api/v1/pangkat']['GET']  = 'master/pangkat_get';
$route['api/v1/jabatan']['GET']  = 'master/jabatan_get';
// CORS preflight - Master Data
$route['api/v1/master/polda']['OPTIONS']         = 'master/polda_options';
$route['api/v1/master/polda/(:num)']['OPTIONS']  = 'master/polda_options/$1';
$route['api/v1/master/polres']['OPTIONS']        = 'master/polres_options';
$route['api/v1/master/polres/(:num)']['OPTIONS'] = 'master/polres_options/$1';
$route['api/v1/master/wilayah']['OPTIONS']       = 'master/wilayah_options';
$route['api/v1/pangkat']['OPTIONS']              = 'master/pangkat_options';
$route['api/v1/jabatan']['OPTIONS']              = 'master/jabatan_options';
// Dashboard / Executive Command Center
$route['api/v1/dashboard/nasional']['GET']      = 'dashboard/nasional_get';
$route['api/v1/dashboard/nasional']['OPTIONS']  = 'dashboard/nasional_get';
$route['api/v1/dashboard/drilldown']['GET']     = 'dashboard/drilldown_get';
$route['api/v1/dashboard/drilldown']['OPTIONS'] = 'dashboard/drilldown_get';
$route['404_override'] = '';
$route['translate_uri_dashes'] = FALSE;
