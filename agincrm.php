<?php
if (!defined('ABSPATH'))
    exit;
/*
Plugin Name: Agin CRM: Sending Leads to CRM
Description: Used by Agin clients to send leads to CRM
Version: 1.0
Requires at least: 5.8
Requires PHP: 7.4.3
Author: Ágin CRM
Author URI: https://agin.com.br/
License: GPLv2 or later
*/

// Captures the POST method when sending the lead and also when configuring the plugin
function agin_capture_lead()
{
    if (isset($_POST['agin_nonce']) && wp_verify_nonce($_POST['agin_nonce'], 'agin_save_settings')) {
        if (isset($_POST['agin_client_id'])) {
            // Capture the text sent by the form
            $client_id = sanitize_text_field($_POST['agin_client_id']);

            // Save the text in the configuration option
            update_option('agin_client_id', $client_id);

            // Redirect to the same configuration page
            wp_redirect(admin_url('options-general.php?page=agin-plugin-config'));
            exit;
        }
    }

    if (isset($_POST['name'])) {
        $json = agin_build_json();
        if ($json !== null) {
            agin_send_lead($json);
        }
    }
}
add_action('init', 'agin_capture_lead');

// Creates a menu and renders the configuration page
function agin_add_settings_page()
{
    add_submenu_page(
        'options-general.php',
        'Agin Plugin Settings',
        'Agin Plugin',
        'manage_options',
        'agin-plugin-config',
        'agin_show_settings_page'
    );
}
add_action('admin_menu', 'agin_add_settings_page');

// Generates the form page to add the client ID
function agin_show_settings_page()
{
    ?>
    <div class="row" style="margin-left: 30%; margin-top: 10%;">
        <div class="card" style="background-color: #1B3063; border-radius: 5px;">
            <div class="card-body">
                <div class="col-md-8">
                    <!-- Add the nonce field here -->
                    <?php wp_nonce_field('agin_save_settings', 'agin_nonce'); ?>
                    <img src="<?php echo esc_url(plugins_url('/assets/icon/favicon-white.svg', __FILE__)); ?>"
                        style="width: 109px; height: 65px;" />
                    <h2 style="color: #ffff;">
                        <?php esc_html_e('Ative o recebimento de leads no CRM Ágin', 'agin-crm'); ?>
                    </h2>
                    <span style="color: #ffff;">
                        <?php esc_html_e('Informe o seu id de cliente no CRM Ágin, com ele os leads do seu site serão enviados automáticamente para nós.', 'agin-crm'); ?>
                    </span>
                    <br />
                    <br />
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('agin-plugin-config');
                        do_settings_sections('agin-plugin-config');

                        // Text input field
                        $agin_client_id = get_option('agin_client_id');
                        ?>
                        <label style="color: #ffff;">
                            <?php esc_html_e('Cliente ID', 'agin-crm'); ?>
                        </label>
                        <input type="text" id="agin_client_id" name="agin_client_id"
                            value="<?php echo esc_attr($agin_client_id); ?>" style="width: 65%;" />
                        <?php
                        submit_button();
                        ?>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// Initializes the plugin configuration at the client
function agin_register_settings()
{
    // Settings group name (used in settings_fields and do_settings_sections functions)
    $group = 'agin-plugin-config';

    // Setting name, option name in the database
    $option = 'agin_client_id';

    // Register the setting
    register_setting($group, $option);
}
add_action('admin_init', 'agin_register_settings');

// Sends the lead to the Ágin API
function agin_send_lead(string $json)
{
    $url = 'https://api.agin.com.br/api/AdicionarLead';

    $args = array(
        'body' => $json,
        'headers' => array('Content-Type' => 'application/json'),
        'sslverify' => false,
        'timeout' => 10,
    );

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        echo esc_html__('Error sending lead to API.', 'agin-crm');
        echo 'WP Error: ' . $response->get_error_message();
    } else {
        echo esc_html(wp_remote_retrieve_body($response));
    }
}



// Builds the JSON with the real estate form fields
function agin_build_json()
{
    $clienteId = get_option('agin_client_id');
    $Nome = "";
    $Email = "";
    $Telefone = "";
    $ImovelCodigo = "";
    $Interesse = "Indefinido";
    $ClienteMensagem = "";
    $Origem = "SITE";

    $finalidadeMappings = [
        "alugar" => "ALUGAR",
        "locacao" => "ALUGAR",
        "comprar" => "COMPRAR",
        "venda" => "COMPRAR",
    ];

    foreach ($finalidadeMappings as $term => $finalidade) {
        if (str_contains(strtolower($_SERVER["REQUEST_URI"]), $term)) {
            $Interesse = $finalidade;
            break;
        }
    }

    $campos = [
        "ImovelCodigo" => ["codigo", 'cod', 'codigo_imovel', "codigoimovel", "idimovel"],
        "Nome" => ["name", "nome"],
        "Email" => ["email"],
        "Telefone" => ["telefone", "telephone", "phone", "fone"],
        "ClienteMensagem" => ["mensagem", "message", "msg", "retornoimovel"],
        "url_imovel" => ["imovelurl", "url"],
        "Interesse" => ["finalidade", "modalidade"],
    ];

    foreach ($_POST as $key => $value) {
        foreach ($campos as $campo => $sinonimos) {
            if (in_array(strtolower($key), $sinonimos)) {
                $$campo = ($campo == "email") ? filter_var($value, FILTER_SANITIZE_EMAIL) : sanitize_text_field($value);
                if ($campo == "url_imovel") {
                    $ClienteMensagem .= " " . $$campo;
                }
            }
        }
    }

    if (empty($Nome) || (empty($Telefone) && empty($Email)) || empty($clienteId)) {
        return null;
    }

    $json = [
        "ClienteId" => $clienteId,
        "Nome" => $Nome,
        "Email" => $Email,
        "Telefone" => $Telefone,
        "ImovelCodigo" => $ImovelCodigo,
        "Interesse" => $Interesse,
        "ClienteMensagem" => $ClienteMensagem,
        "Origem" => $Origem
    ];
    return json_encode($json);
}
