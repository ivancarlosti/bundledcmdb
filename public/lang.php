<?php
// lang.php — Multi-language support for CMDB
// Languages: pt_BR (default), es_MX, en_US

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// DB values for Status column (always English, stored in MySQL)
define('STATUS_DB_VALUES', [
    'In Use',
    'In Stock',
    'In Repair',
    'Decommissioned',
    'Lost or Stolen'
]);

// All supported languages
define('LANG_SUPPORTED', ['pt_BR', 'es_MX', 'en_US']);
define('LANG_DEFAULT', 'pt_BR');

// Language metadata (native name + flag CDN code)
function lang_meta() {
    return [
        'pt_BR' => ['native' => 'Português (Brasil)', 'flag' => 'br', 'label' => 'PT'],
        'es_MX' => ['native' => 'Español (México)',  'flag' => 'mx', 'label' => 'ES'],
        'en_US' => ['native' => 'English (US)',        'flag' => 'us', 'label' => 'EN'],
    ];
}

// Initialize language: read ?lang= param or session, default pt_BR
function lang_init() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    // Check for language switch via GET
    if (isset($_GET['lang']) && in_array($_GET['lang'], LANG_SUPPORTED, true)) {
        $_SESSION['lang'] = $_GET['lang'];
        // Redirect to same page without query param to keep URLs clean
        $qs = $_GET;
        unset($qs['lang']);
        $url = strtok($_SERVER['REQUEST_URI'], '?');
        if (!empty($qs)) {
            $url .= '?' . http_build_query($qs);
        }
        header('Location: ' . $url);
        exit();
    }
    if (!isset($_SESSION['lang']) || !in_array($_SESSION['lang'], LANG_SUPPORTED, true)) {
        $_SESSION['lang'] = LANG_DEFAULT;
    }
}

// Return translated string for a given key
function lang($key) {
    $lang = $_SESSION['lang'] ?? LANG_DEFAULT;
    $strings = lang_strings();
    return $strings[$lang][$key] ?? $strings[LANG_DEFAULT][$key] ?? $key;
}

// All translation strings organized by language
function lang_strings() {
    return [
        'pt_BR' => [
            // --- Common ---
            'title_cmdb'        => 'CMDB Empresa:',
            'signed_in_as'      => 'Logado como:',
            'logout'            => 'Sair',

            // --- main.php search ---
            'search_field'      => 'Campo de Busca',
            'search_text'       => 'Texto de Busca',
            'search_btn'        => 'Buscar',
            'clear'             => 'Limpar',
            'select_field'      => 'Selecionar campo',
            'export_btn'        => 'Exportar para Excel',
            'manage_perm'       => 'Gerenciar Permissões',

            // --- main.php table ---
            'save_changes'      => 'Salvar Alterações',
            'showing'           => 'Mostrando',
            'to'                => 'até',
            'of'                => 'de',
            'records'           => 'registros',
            'previous'          => 'Anterior',
            'next'              => 'Próximo',
            'page'              => 'Página',
            'no_files'          => 'Sem arquivos',
            'file_singular'     => 'arquivo',
            'file_plural'       => 'arquivos',
            'true_label'        => 'Verdadeiro',
            'false_label'       => 'Falso',
            'switch_company'    => 'Superadmin - Trocar Empresa:',

            // --- Status options ---
            'status_in_use'     => 'Em Uso',
            'status_stock'      => 'Em Estoque',
            'status_repair'     => 'Em Reparo',
            'status_decomm'     => 'Descomissionado',
            'status_lost'       => 'Perdido ou Roubado',

            // --- asset.php ---
            'back_to_cmdb'      => 'Voltar para CMDB Empresa:',
            'row_details'       => 'Detalhes do Registro CMDB (NS #',
            'upload_title'      => 'Enviar Novo Arquivo',
            'upload_btn'        => 'Enviar',
            'existing_files'    => 'Arquivos Existentes',
            'no_files_found'    => 'Nenhum arquivo encontrado.',
            'delete_btn'        => 'Excluir',
            'confirm_delete'    => 'Excluir este arquivo?',

            // --- index.php ---
            'login_title'       => 'CMDB - Login',
            'login_prompt'      => 'Faça login usando sua conta corporativa.',
            'sso_btn'           => 'Entrar com SSO',

            // --- manage_permissions.php ---
            'perm_title'        => 'Gerenciamento de Permissões',
            'perm_grant'        => 'Conceder Permissões',
            'perm_promote'      => 'Selecione um usuário para promover a Admin ou SuperAdmin.',
            'perm_user_label'   => 'Usuário:',
            'perm_role_label'   => 'Função:',
            'perm_select_user'  => '-- Selecionar Usuário --',
            'perm_btn_grant'    => 'Conceder Permissão',
            'perm_current'      => 'Administradores & SuperAdmins Atuais',
            'perm_back'         => 'Voltar ao Painel',
            'perm_email'        => 'Email',
            'perm_company'      => 'Empresa',
            'perm_role'         => 'Função Atual',
            'perm_actions'      => 'Ações',
            'perm_you'          => '(Você)',
            'perm_remove'       => 'Remover',
            'perm_confirm_remove' => 'Tem certeza que deseja remover os direitos de admin deste usuário?',
            'perm_no_admins'    => 'Nenhum admin encontrado.',

            // --- Roles ---
            'role_manager'      => 'Gerente',
            'role_admin'        => 'Admin',
            'role_superadmin'   => 'SuperAdmin',
            'role_user'         => 'Usuário',

            // --- Theme ---
            'theme_system'      => 'Sistema',
            'theme_light'       => 'Claro',
            'theme_dark'        => 'Escuro',
        ],
        'es_MX' => [
            // --- Common ---
            'title_cmdb'        => 'CMDB Empresa:',
            'signed_in_as'      => 'Conectado como:',
            'logout'            => 'Cerrar Sesión',

            // --- main.php search ---
            'search_field'      => 'Campo de Búsqueda',
            'search_text'       => 'Texto de Búsqueda',
            'search_btn'        => 'Buscar',
            'clear'             => 'Limpiar',
            'select_field'      => 'Seleccionar campo',
            'export_btn'        => 'Exportar a Excel',
            'manage_perm'       => 'Gestionar Permisos',

            // --- main.php table ---
            'save_changes'      => 'Guardar Cambios',
            'showing'           => 'Mostrando',
            'to'                => 'a',
            'of'                => 'de',
            'records'           => 'registros',
            'previous'          => 'Anterior',
            'next'              => 'Siguiente',
            'page'              => 'Página',
            'no_files'          => 'Sin archivos',
            'file_singular'     => 'archivo',
            'file_plural'       => 'archivos',
            'true_label'        => 'Verdadero',
            'false_label'       => 'Falso',
            'switch_company'    => 'Superadmin - Cambiar Empresa:',

            // --- Status options ---
            'status_in_use'     => 'En Uso',
            'status_stock'      => 'En Stock',
            'status_repair'     => 'En Reparación',
            'status_decomm'     => 'Retirado',
            'status_lost'       => 'Perdido o Robado',

            // --- asset.php ---
            'back_to_cmdb'      => 'Volver a CMDB Empresa:',
            'row_details'       => 'Detalles del Registro CMDB (NS #',
            'upload_title'      => 'Subir Nuevo Archivo',
            'upload_btn'        => 'Subir',
            'existing_files'    => 'Archivos Existentes',
            'no_files_found'    => 'No se encontraron archivos.',
            'delete_btn'        => 'Eliminar',
            'confirm_delete'    => '¿Eliminar este archivo?',

            // --- index.php ---
            'login_title'       => 'CMDB - Iniciar Sesión',
            'login_prompt'      => 'Inicie sesión con su cuenta corporativa.',
            'sso_btn'           => 'Iniciar sesión con SSO',

            // --- manage_permissions.php ---
            'perm_title'        => 'Gestión de Permisos',
            'perm_grant'        => 'Conceder Permisos',
            'perm_promote'      => 'Seleccione un usuario para promover a Admin o SuperAdmin.',
            'perm_user_label'   => 'Usuario:',
            'perm_role_label'   => 'Rol:',
            'perm_select_user'  => '-- Seleccionar Usuario --',
            'perm_btn_grant'    => 'Conceder Permiso',
            'perm_current'      => 'Administradores & SuperAdmins Actuales',
            'perm_back'         => 'Volver al Panel',
            'perm_email'        => 'Correo',
            'perm_company'      => 'Empresa',
            'perm_role'         => 'Rol Actual',
            'perm_actions'      => 'Acciones',
            'perm_you'          => '(Tú)',
            'perm_remove'       => 'Eliminar',
            'perm_confirm_remove' => '¿Está seguro de eliminar los derechos de admin de este usuario?',
            'perm_no_admins'    => 'No se encontraron administradores.',

            // --- Roles ---
            'role_manager'      => 'Gerente',
            'role_admin'        => 'Admin',
            'role_superadmin'   => 'SuperAdmin',
            'role_user'         => 'Usuario',

            // --- Theme ---
            'theme_system'      => 'Sistema',
            'theme_light'       => 'Claro',
            'theme_dark'        => 'Oscuro',
        ],
        'en_US' => [
            // --- Common ---
            'title_cmdb'        => 'CMDB Company:',
            'signed_in_as'      => 'Signed in as:',
            'logout'            => 'Logout',

            // --- main.php search ---
            'search_field'      => 'Search Field',
            'search_text'       => 'Search Text',
            'search_btn'        => 'Search',
            'clear'             => 'Clear',
            'select_field'      => 'Select field',
            'export_btn'        => 'Export to Excel',
            'manage_perm'       => 'Manage Permissions',

            // --- main.php table ---
            'save_changes'      => 'Save Changes',
            'showing'           => 'Showing',
            'to'                => 'to',
            'of'                => 'of',
            'records'           => 'records',
            'previous'          => 'Previous',
            'next'              => 'Next',
            'page'              => 'Page',
            'no_files'          => 'No files',
            'file_singular'     => 'file',
            'file_plural'       => 'files',
            'true_label'        => 'True',
            'false_label'       => 'False',
            'switch_company'    => 'Superadmin - Switch Company:',

            // --- Status options ---
            'status_in_use'     => 'In Use',
            'status_stock'      => 'In Stock',
            'status_repair'     => 'In Repair',
            'status_decomm'     => 'Decommissioned',
            'status_lost'       => 'Lost or Stolen',

            // --- asset.php ---
            'back_to_cmdb'      => 'Back to CMDB Company:',
            'row_details'       => 'CMDB Row Details (SN #',
            'upload_title'      => 'Upload New File',
            'upload_btn'        => 'Upload',
            'existing_files'    => 'Existing Files',
            'no_files_found'    => 'No files found.',
            'delete_btn'        => 'Delete',
            'confirm_delete'    => 'Delete this file?',

            // --- index.php ---
            'login_title'       => 'CMDB - Login',
            'login_prompt'      => 'Please sign in using your company account.',
            'sso_btn'           => 'Sign in with SSO',

            // --- manage_permissions.php ---
            'perm_title'        => 'Permission Management',
            'perm_grant'        => 'Grant Permissions',
            'perm_promote'      => 'Select a user to promote to Admin or SuperAdmin status.',
            'perm_user_label'   => 'User:',
            'perm_role_label'   => 'Role:',
            'perm_select_user'  => '-- Select User --',
            'perm_btn_grant'    => 'Grant Permission',
            'perm_current'      => 'Current Admins & SuperAdmins',
            'perm_back'         => 'Back to Dashboard',
            'perm_email'        => 'Email',
            'perm_company'      => 'Company',
            'perm_role'         => 'Current Role',
            'perm_actions'      => 'Actions',
            'perm_you'          => '(You)',
            'perm_remove'       => 'Remove',
            'perm_confirm_remove' => 'Are you sure you want to remove admin rights from this user?',
            'perm_no_admins'    => 'No admins found.',

            // --- Roles ---
            'role_manager'      => 'Manager',
            'role_admin'        => 'Admin',
            'role_superadmin'   => 'SuperAdmin',
            'role_user'         => 'User',

            // --- Theme ---
            'theme_system'      => 'System',
            'theme_light'       => 'Light',
            'theme_dark'        => 'Dark',
        ],
    ];
}

// Generate HTML for language flag buttons (top-right corner)
function lang_flag_buttons($current_page = '') {
    $meta = lang_meta();
    $current = $_SESSION['lang'] ?? LANG_DEFAULT;
    $html = '<div class="lang-flags">';
    foreach (LANG_SUPPORTED as $code) {
        $m = $meta[$code];
        $active = ($code === $current) ? ' active' : '';
        $esc_page = htmlspecialchars($current_page ?: basename($_SERVER['SCRIPT_NAME']), ENT_QUOTES, 'UTF-8');
        $url = $esc_page . '?lang=' . $code;
        // Append existing GET params (except lang)
        $existing = $_GET;
        unset($existing['lang']);
        if (!empty($existing)) {
            $url .= '&' . http_build_query($existing);
        }
        $html .= '<a href="' . $url . '" class="flag-link' . $active . '" title="' . htmlspecialchars($m['native'], ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<img src="https://flagcdn.com/w40/' . $m['flag'] . '.png" alt="' . $m['label'] . '" width="28" height="20" style="vertical-align: middle;">';
        $html .= '</a>';
    }
    $html .= '</div>';
    return $html;
}

// Generate HTML for theme toggle button
function theme_toggle_button() {
    $theme_label = lang('theme_system');
    $html = '<div class="theme-toggle">';
    $html .= '<button type="button" id="themeToggleBtn" title="' . htmlspecialchars($theme_label, ENT_QUOTES, 'UTF-8') . '" onclick="cycleTheme()">';
    $html .= '<span id="themeIcon">⏻</span>';
    $html .= '</button>';
    $html .= '</div>';
    return $html;
}

// Returns a map of [db_value => translated_label] for Status dropdowns.
// The DB always stores English values; the display label depends on the current language.
function lang_status_options() {
    $keys = [
        'status_in_use',
        'status_stock',
        'status_repair',
        'status_decomm',
        'status_lost'
    ];
    $db_values = STATUS_DB_VALUES;
    $result = [];
    foreach ($keys as $i => $k) {
        $result[$db_values[$i]] = lang($k);
    }
    return $result;
}
