<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'mem_form';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.9.0';
$plugin['author'] = 'Michael Manfre';
$plugin['author_uri'] = 'http://manfre.net/';
$plugin['description'] = 'A library plugin that provides support for html forms.';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '2';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '0';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

$plugin['textpack'] = <<<EOTP
#@public
#@owner mem_form
#@language en, en-gb, en-us
mem_form_error_file_extension => File upload failed for field {label}.
mem_form_error_file_failed => Failed to upload file for field {label}.
mem_form_error_file_size => Failed to upload File for field {label}. File is too large.
mem_form_field_missing => The field {label} is required.
mem_form_expired => The form has expired.
mem_form_misconfigured => The mem_form is misconfigured. You must specify the "form" attribute.
mem_form_sorry => The form is currently unavailable.
mem_form_used => This form has already been used to submit.
mem_form_general_inquiry => Enquiry
mem_form_invalid_email => The email address {email} is invalid.
mem_form_invalid_host => The host {domain} is invalid.
mem_form_invalid_utf8 => Invalid UTF8 string for field {label}.
mem_form_invalid_value => The value "{value}" is invalid for the input field {label}.
mem_form_invalid_format => The input field {label} must match the format "{example}".
mem_form_invalid_too_many_selected => The input field {label} only allows {count} selected {plural}.
mem_form_item => item
mem_form_items => items
mem_form_max_warning => The input field {label} must be smaller than {max} characters long.
mem_form_min_warning => The input field {label} must be at least {min} characters long.
mem_form_refresh => Refresh
mem_form_spam => Your submission was blocked by a spam filter.
mem_form_submitted_thanks => You have successfully submitted the form. Thank you.
EOTP;
// End of Textpack

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
$mem_glz_custom_fields_plugin = load_plugin('glz_custom_fields');

// needed for MLP
define( 'MEM_FORM_PREFIX' , 'mem_form' );

if (class_exists('\Textpattern\Tag\Registry')) {
    Txp::get('\Textpattern\Tag\Registry')
        ->register('mem_form')
        ->register('mem_form_checkbox')
        ->register('mem_form_email')
        ->register('mem_form_file')
        ->register('mem_form_hidden')
        ->register('mem_form_secret')
        ->register('mem_form_select')
        ->register('mem_form_select_category')
        ->register('mem_form_select_range')
        ->register('mem_form_select_section')
        ->register('mem_form_serverinfo')
        ->register('mem_form_radio')
        ->register('mem_form_submit')
        ->register('mem_form_text')
        ->register('mem_form_textarea');
}

function mem_form($atts, $thing = '', $default = false)
{
    global $sitename, $prefs, $file_max_upload_size, $mem_form_error, $mem_form_submit,
        $mem_form, $mem_form_labels, $mem_form_values, $mem_form_default_break,
        $mem_form_default, $mem_form_type, $mem_form_thanks_form,
        $mem_glz_custom_fields_plugin;

    extract(mem_form_lAtts(array(
        'form'             => '',
        'thanks_form'      => '',
        'thanks'           => graf(gTxt('mem_form_submitted_thanks')),
        'label'            => '',
        'type'             => '',
        'redirect'         => '',
        'redirect_form'    => '',
        'class'            => 'memForm',
        'enctype'          => '',
        'file_accept'      => '',
        'max_file_size'    => $file_max_upload_size,
        'form_expired_msg' => gTxt('mem_form_expired'),
        'show_error'       => 1,
        'show_input'       => 1,
        'default_break'    => br,
    ), $atts));

    if (empty($type) or (empty($form) && empty($thing))) {
        trigger_error('Argument not specified for mem_form tag', E_USER_WARNING);

        return '';
    }

    $out = '';

    // init error structure
    mem_form_error();

    $mem_form_type = $type;

    $mem_form_default = is_array($default) ? $default : array();
    callback_event('mem_form.defaults');

    unset($atts['show_error'], $atts['show_input']);
    $mem_form_id = md5(serialize($atts).preg_replace('/[\t\s\r\n]/','',$thing));
    $mem_form_submit = (ps('mem_form_id') == $mem_form_id);

    $nonce   = doSlash(ps('mem_form_nonce'));
    $renonce = false;

    if ($mem_form_submit) {
        safe_delete('txp_discuss_nonce', 'issue_time < date_sub(now(), interval 10 minute)');

        if ($rs = safe_row('used', 'txp_discuss_nonce', "nonce = '$nonce'")) {
            if ($rs['used']) {
                unset($mem_form_error);
                mem_form_error(gTxt('mem_form_used'));
                $renonce = true;

                $_POST['mem_form_submit'] = true;
                $_POST['mem_form_id'] = $mem_form_id;
                $_POST['mem_form_nonce'] = $nonce;
            }
        } else {
            mem_form_error($form_expired_msg);
            $renonce = true;
        }
    }

    if ($mem_form_submit and $nonce and !$renonce) {
        $mem_form_nonce = $nonce;
    } elseif (!$show_error or $show_input) {
        $mem_form_nonce = md5(uniqid(rand(), true));
        safe_insert('txp_discuss_nonce', "issue_time = now(), nonce = '$mem_form_nonce'");
    }

    $form = ($form) ? fetch_form($form) : $thing;
    $form = parse($form);

    if ($mem_form_submit && empty($mem_form_error)) {
        // let plugins validate after individual fields are validated
        callback_event('mem_form.validate');
    }

    if (!$mem_form_submit) {
      # don't show errors or send mail
    } elseif (mem_form_error()) {
        if ($show_error or !$show_input) {
            $out .= mem_form_display_error();

            if (!$show_input) {
                return $out;
            }
        }
    } elseif ($show_input and is_array($mem_form)) {
        if ($mem_glz_custom_fields_plugin) {
            // prep the values
            glz_custom_fields_before_save();
        }

        callback_event('mem_form.spam');

        /// load and check spam plugins/
        $evaluator =& get_mem_form_evaluator();
        $is_spam = $evaluator->is_spam();

        if ($is_spam) {
            return gTxt('mem_form_spam');
        }

        $mem_form_thanks_form = ($thanks_form ? fetch_form($thanks_form) : $thanks);

        safe_update('txp_discuss_nonce', "used = '1', issue_time = now()", "nonce = '$nonce'");

        $result = callback_event('mem_form.submit');

        if (mem_form_error()) {
            $out .= mem_form_display_error();
            $redirect = false;
        }

        $thanks_form = $mem_form_thanks_form;
        unset($mem_form_thanks_form);

        if (!empty($result)) {
            return $result;
        }

        if (mem_form_error() and $show_input) {
            // no-op, reshow form with errors
        } elseif ($redirect) {
            $_POST = array();

            while (@ob_end_clean());

            $uri = hu.ltrim($redirect,'/');

            if (empty($_SERVER['FCGI_ROLE']) and empty($_ENV['FCGI_ROLE'])) {
                txp_status_header('303 See Other');
                header('Location: '.$uri);
                header('Connection: close');
                header('Content-Length: 0');
            } else {
                $uri = htmlspecialchars($uri);
                $refresh = gTxt('mem_form_refresh');

                if (!empty($redirect_form)) {
                    $redirect_form = fetch_form($redirect_form);

                    echo str_replace('{uri}', $uri, $redirect_form);
                }

                if (empty($redirect_form)) {
                    echo <<<END
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
    <title>$sitename</title>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta http-equiv="refresh" content="0;url=$uri" />
</head>
<body>
<a href="$uri">$refresh</a>
</body>
</html>
END;
                }
            }
            exit;
        } else {
            return '<div class="memThanks" id="mem'.$mem_form_id.'">' .
                $thanks_form . '</div>';
        }
    }

    if ($show_input) {
        $file_accept = (!empty($file_accept) ? ' accept="'.$file_accept.'"' : '');

        $class = htmlspecialchars($class);

        $enctype = !empty($enctype) ? ' enctype="'.$enctype.'"' : '';

        return '<form method="post"'.((!$show_error and $mem_form_error) ? '' : ' id="mem'.$mem_form_id.'"').' class="'.$class.'" action="'.htmlspecialchars(serverSet('REQUEST_URI')).'#mem'.$mem_form_id.'"'.$file_accept.$enctype.'>'.
            ( $label ? n.'<fieldset>' : n.'<div>' ).
            ( $label ? n.'<legend>'.htmlspecialchars($label).'</legend>' : '' ).
            $out.
            n.'<input type="hidden" name="mem_form_nonce" value="'.$mem_form_nonce.'" />'.
            n.'<input type="hidden" name="mem_form_id" value="'.$mem_form_id.'" />'.
            (!empty($max_file_size) ? n.'<input type="hidden" name="MAX_FILE_SIZE" value="'.$max_file_size.'" />' : '' ).
            callback_event('mem_form.display','',1).
            $form.
            callback_event('mem_form.display').
            ( $label ? (n.'</fieldset>') : (n.'</div>') ).
            n.'</form>';
    }

    return '';
}

function mem_form_text($atts)
{
    global $mem_form_error, $mem_form_submit, $mem_form_default, $mem_form_default_break;

    extract(mem_form_lAtts(array(
        'break'        => $mem_form_default_break,
        'default'      => '',
        'isError'      => '',
        'label'        => gTxt('text'),
        'placeholder'  => '',
        'max'          => 100,
        'min'          => 0,
        'name'         => '',
        'class'        => 'memText',
        'required'     => 1,
        'size'         => '',
        'password'     => 0,
        'format'       => '',
        'example'      => '',
        'escape_value' => 1,
        'attrs'        => ''
    ), $atts));

    $min = intval($min);
    $max = intval($max);
    $size = intval($size);

    if (empty($name)) {
        $name = mem_form_label2name($label);
    }

    if ($mem_form_submit) {
        $value = trim(ps($name));
        $utf8len = preg_match_all("/./su", $value, $utf8ar);
        $hlabel = empty($label) ? htmlspecialchars($name) : htmlspecialchars($label);


        if (strlen($value) == 0 && $required) {
            $mem_form_error[] = gTxt('mem_form_field_missing', array('{label}'=>$hlabel));
            $isError = true;
        } elseif ($required && !empty($format) && !preg_match($format, $value)) {
            //echo "format=$format<br />value=$value<br />";
            $mem_form_error[] = gTxt('mem_form_invalid_format', array('{label}'=>$hlabel, '{example}'=> htmlspecialchars($example)));
            $isError = true;
        } elseif (strlen($value)) {
            if (!$utf8len) {
                $mem_form_error[] = gTxt('mem_form_invalid_utf8', array('{label}'=>$hlabel));
                $isError = true;
            } elseif ($min and $utf8len < $min) {
                $mem_form_error[] = gTxt('mem_form_min_warning', array('{label}'=>$hlabel, '{min}'=>$min));
                $isError = true;
            } elseif ($max and $utf8len > $max) {
                $mem_form_error[] = gTxt('mem_form_max_warning', array('{label}'=>$hlabel, '{max}'=>$max));
                $isError = true;
            } else {
                $isError = false === mem_form_store($name, $label, $value);
            }
        }
    } else {
        if (isset($mem_form_default[$name])) {
            $value = $mem_form_default[$name];
        } else {
            $value = $default;
        }
    }

    $size = ($size) ? ' size="'.$size.'"' : '';
    $maxlength = ($max) ? ' maxlength="'.$max.'"' : '';
    $placeholder = ($placeholder) ? ' placeholder="'.htmlspecialchars($placeholder).'"' : '';

    $isError = $isError ? "errorElement" : '';

    $memRequired = $required ? 'memRequired' : '';
    $class = htmlspecialchars($class);

    if ($escape_value) {
        $value = htmlspecialchars($value);
    }

    return '<label for="'.$name.'" class="'.$class.' '.$memRequired.$isError.' '.$name.'">'.htmlspecialchars($label).'</label>'.$break.
        '<input type="'.($password ? 'password' : 'text').'" id="'.$name.'" class="'.$class.' '.$memRequired.$isError.'" name="'.$name.'" value="'.$value.'"'.$size.$maxlength.$placeholder.
        ( !empty($attrs) ? ' ' . $attrs : '').' />';
}


function mem_form_file($atts)
{
    global $mem_form_submit, $mem_form_error, $mem_form_default, $file_max_upload_size, $tempdir, $mem_form_default_break;

    extract(mem_form_lAtts(array(
        'break'         => $mem_form_default_break,
        'isError'       => '',
        'label'         => gTxt('file'),
        'name'          => '',
        'class'         => 'memFile',
        'size'          => '',
        'accept'        => '',
        'no_replace'    => 1,
        'max_file_size' => $file_max_upload_size,
        'required'      => 1,
        'default'       => false,
    ), $atts));

    $fname = ps('file_'.$name);
    $frealname = ps('file_info_'.$name.'_name');
    $ftype = ps('file_info_'.$name.'_type');

    if (empty($name)) {
        $name = mem_form_label2name($label);
    }

    $out = '';

    if ($mem_form_submit) {
        if (!empty($fname)) {
            // see if user uploaded a different file to replace already uploaded
            if (isset($_FILES[$name]) && !empty($_FILES[$name]['tmp_name'])) {
                // unlink last temp file
                if (file_exists($fname) && substr_compare($fname, $tempdir, 0, strlen($tempdir), 1)==0) {
                    unlink($fname);
                }

                $fname = '';
            } else {
                // pass through already uploaded filename
                mem_form_store($name, $label, array('tmp_name'=>$fname, 'name' => $frealname, 'type' => $ftype));
                $out .= "<input type='hidden' name='file_".$name."' value='".htmlspecialchars($fname)."' />"
                        . "<input type='hidden' name='file_info_".$name."_name' value='".htmlspecialchars($frealname)."' />"
                        . "<input type='hidden' name='file_info_".$name."_type' value='".htmlspecialchars($ftype)."' />";
            }
        }

        if (empty($fname)) {
            $hlabel = empty($label) ? htmlspecialchars($name) : htmlspecialchars($label);

            $fname = $_FILES[$name]['tmp_name'];
            $frealname = $_FILES[$name]['name'];
            $ftype = $_FILES[$name]['type'];
            $err = 0;

            switch ($_FILES[$name]['error']) {
                case UPLOAD_ERR_OK:
                    if (is_uploaded_file($fname) and $max_file_size >= filesize($fname)) {
                        mem_form_store($name, $label, $_FILES[$name]);
                    } elseif (!is_uploaded_file($fname)) {
                        if ($required) {
                            $mem_form_error[] = gTxt('mem_form_error_file_failed', array('{label}'=>$hlabel));
                            $err = 1;
                        }
                    } else {
                        $mem_form_error[] = gTxt('mem_form_error_file_size', array('{label}'=>$hlabel));
                        $err = 1;
                    }
                    break;

                case UPLOAD_ERR_NO_FILE:
                    if ($required) {
                        $mem_form_error[] = gTxt('mem_form_field_missing', array('{label}'=>$hlabel));
                        $err = 1;
                    }
                    break;

                case UPLOAD_ERR_EXTENSION:
                    $mem_form_error[] = gTxt('mem_form_error_file_extension', array('{label}'=>$hlabel));
                    $err = 1;
                    break;

                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $mem_form_error[] = gTxt('mem_form_error_file_size', array('{label}'=>$hlabel));
                    $err = 1;
                    break;

                default:
                    $mem_form_error[] = gTxt('mem_form_error_file_failed', array('{label}'=>$hlabel));
                    $err = 1;
                    break;
            }

            if (!$err) {
                // store as a txp tmp file to be used later
                $fname = get_uploaded_file($fname);
                $err = false === mem_form_store($name, $label, array('tmp_name'=>$fname, 'name' => $frealname, 'type' => $ftype));

                if ($err) {
                    // clean up file
                    @unlink($fname);
                } else {
                    $out .= "<input type='hidden' name='file_".$name."' value='".htmlspecialchars($fname)."' />"
                            . "<input type='hidden' name='file_info_".$name."_name' value='".htmlspecialchars($_FILES[$name]['name'])."' />"
                            . "<input type='hidden' name='file_info_".$name."_type' value='".htmlspecialchars($_FILES[$name]['type'])."' />";
                }
            }

            $isError = $err ? 'errorElement' : '';
        }
    } else {
        if (isset($mem_form_default[$name])) {
            $value = $mem_form_default[$name];
        } elseif (is_array($default)) {
            $value = $default;
        }

        if (is_array(@$value)) {
            $fname = @$value['tmp_name'];
            $frealname = @$value['name'];
            $ftype = @$value['type'];
            $out .= "<input type='hidden' name='file_".$name."' value='".htmlspecialchars($fname)."' />"
                . "<input type='hidden' name='file_info_".$name."_name' value='".htmlspecialchars($frealname)."' />"
                . "<input type='hidden' name='file_info_".$name."_type' value='".htmlspecialchars($ftype)."' />";
        }
    }

    $memRequired = $required ? 'memRequired' : '';
    $class = htmlspecialchars($class);

    $size = ($size) ? ' size="'.$size.'"' : '';
    $accept = (!empty($accept) ? ' accept="'.$accept.'"' : '');

    $field_out = '<label for="'.$name.'" class="'.$class.' '.$memRequired.$isError.' '.$name.'">'.htmlspecialchars($label).'</label>'.$break;

    if (!empty($frealname) && $no_replace) {
        $field_out .= '<div id="'.$name.'">'.htmlspecialchars($frealname) . ' <span id="'.$name.'_ftype">('. htmlspecialchars($ftype).')</span></div>';
    } else {
        $field_out .= '<input type="file" id="'.$name.'" class="'.$class.' '.$memRequired.$isError.'" name="'.$name.'"' .$size.' />';
    }

    return $out.$field_out;
}

function mem_form_textarea($atts, $thing = '')
{
    global $mem_form_error, $mem_form_submit, $mem_form_default, $mem_form_default_break;

    extract(mem_form_lAtts(array(
        'break'        => $mem_form_default_break,
        'cols'         => 58,
        'default'      => '',
        'isError'      => '',
        'label'        => gTxt('textarea'),
        'placeholder'  => '',
        'max'          => 10000,
        'min'          => 0,
        'name'         => '',
        'class'        => 'memTextarea',
        'required'     => 1,
        'rows'         => 8,
        'escape_value' => 1,
        'attrs'        => ''
    ), $atts));

    $min = intval($min);
    $max = intval($max);
    $cols = intval($cols);
    $rows = intval($rows);

    if (empty($name)) {
        $name = mem_form_label2name($label);
    }

    if ($mem_form_submit) {
        $value = preg_replace('/^\s*[\r\n]/', '', rtrim(ps($name)));
        $utf8len = preg_match_all("/./su", ltrim($value), $utf8ar);
        $hlabel = htmlspecialchars($label);

        if (strlen(ltrim($value))) {
            if (!$utf8len) {
                $mem_form_error[] = gTxt('mem_form_invalid_utf8', array('{label}'=>$hlabel));
                $isError = true;
            } elseif ($min and $utf8len < $min) {
                $mem_form_error[] = gTxt('mem_form_min_warning', array('{label}'=>$hlabel, '{min}'=>$min));
                $isError = true;
            } elseif ($max and $utf8len > $max) {
                $mem_form_error[] = gTxt('mem_form_max_warning', array('{label}'=>$hlabel, '{max}'=>$max));
                $isError = true;
            } else {
                $isError = false === mem_form_store($name, $label, $value);
            }
        } elseif ($required) {
            $mem_form_error[] = gTxt('mem_form_field_missing', array('{label}'=>$hlabel));
            $isError = true;
        }
    } else {
        if (isset($mem_form_default[$name])) {
            $value = $mem_form_default[$name];
        } elseif (!empty($default)) {
            $value = $default;
        } else {
            $value = parse($thing);
        }
    }

    $isError = $isError ? 'errorElement' : '';
    $memRequired = $required ? 'memRequired' : '';
    $class = htmlspecialchars($class);
    $placeholder = ($placeholder) ? ' placeholder="'.htmlspecialchars($placeholder).'"' : '';

    if ($escape_value) {
        $value = htmlspecialchars($value);
    }

    return '<label for="'.$name.'" class="'.$class.' '.$memRequired.$isError.' '.$name.'">'.htmlspecialchars($label).'</label>'.$break.
        '<textarea id="'.$name.'" class="'.$class.' '.$memRequired.$isError.'" name="'.$name.'" cols="'.$cols.'" rows="'.$rows.'"'.$placeholder.
        ( !empty($attrs) ? ' ' . $attrs : '').'>'.$value.'</textarea>';
}

function mem_form_email($atts)
{
    global $mem_form_error, $mem_form_submit, $mem_form_from, $mem_form_default, $mem_form_default_break;

    extract(mem_form_lAtts(array(
        'default'     => '',
        'isError'     => '',
        'label'       => gTxt('email'),
        'placeholder' => '',
        'max'         => 100,
        'min'         => 0,
        'name'        => '',
        'required'    => 1,
        'break'       => $mem_form_default_break,
        'size'        => '',
        'class'       => 'memEmail',
    ), $atts));

    if (empty($name)) {
        $name = mem_form_label2name($label);
    }

    if ($mem_form_submit) {
        $email = trim(ps($name));

        if (strlen($email)) {
            if (!is_valid_email($email)) {
                $mem_form_error[] = gTxt('mem_form_invalid_email', array('{email}'=>htmlspecialchars($email)));
                $isError = true;
            } else {
                preg_match("/@(.+)$/", $email, $match);
                $domain = $match[1];

                if (is_callable('checkdnsrr') and checkdnsrr('textpattern.com.','A') and !checkdnsrr($domain.'.','MX') and !checkdnsrr($domain.'.','A')) {
                    $mem_form_error[] = gTxt('mem_form_invalid_host', array('{domain}'=>htmlspecialchars($domain)));
                    $isError = true;
                } else {
                    $mem_form_from = $email;
                }
            }
        }
    } else {
        if (isset($mem_form_default[$name])) {
            $email = $mem_form_default[$name];
        } else {
            $email = $default;
        }
    }

    return mem_form_text(array(
        'default'     => $email,
        'isError'     => $isError,
        'label'       => $label,
        'placeholder' => $placeholder,
        'max'         => $max,
        'min'         => $min,
        'name'        => $name,
        'required'    => $required,
        'break'       => $break,
        'size'        => $size,
        'class'       => $class,
    ));
}

function mem_form_select_section($atts)
{
    extract(mem_form_lAtts(array(
        'exclude'   => '',
        'sort'      => 'name ASC',
        'delimiter' => ',',
    ), $atts, false));

    if (!empty($exclude)) {
        $exclusion = array_map('trim', explode($delimiter, preg_replace('/[\r\n\t\s]+/', ' ',$exclude)));
        $exclusion = array_map('strtolower', $exclusion);

        if (count($exclusion)) {
            $exclusion = join($delimiter, quote_list($exclusion));
        }
    }

    $where = empty($exclusion) ? '1=1' : 'LOWER(name) NOT IN ('.$exclusion.')';

    $sort = empty($sort) ? '' : ' ORDER BY '. doSlash($sort);

    $rs = safe_rows('name, title','txp_section',$where . $sort);

    $items = array();
    $values = array();

    if ($rs) {
        foreach($rs as $r) {
            $items[] = $r['title'];
            $values[] = $r['name'];
        }
    }

    unset($atts['exclude'], $atts['sort']);

    $atts['items'] = join($delimiter, $items);
    $atts['values'] = join($delimiter, $values);

    return mem_form_select($atts);
}

function mem_form_select_category($atts)
{
    extract(mem_form_lAtts(array(
        'root'      => 'root',
        'exclude'   => '',
        'delimiter' => ',',
        'type'      => 'article'
    ), $atts, false));

    $rs = getTree($root, $type);

    if (!empty($exclude)) {
        $exclusion = array_map('trim', explode($delimiter, preg_replace('/[\r\n\t\s]+/', ' ',$exclude)));
        $exclusion = array_map('strtolower', $exclusion);
    } else {
        $exclusion = array();
    }

    $items = array();
    $values = array();

    if ($rs) {
        foreach ($rs as $cat) {
            if (count($exclusion) && in_array(strtolower($cat['name']), $exclusion)) {
                continue;
            }

            $items[] = $cat['title'];
            $values[] = $cat['name'];
        }
    }

    unset($atts['root'], $atts['type']);

    $atts['items'] = join($delimiter, $items);
    $atts['values'] = join($delimiter, $values);

    return mem_form_select($atts);
}

function mem_form_select_range($atts)
{
    global $mem_form_default_break;

    $latts = mem_form_lAtts(array(
        'start'        => 0,
        'stop'         => false,
        'step'         => 1,
        'name'         => '',
        'break'        => $mem_form_default_break,
        'delimiter'    => ',',
        'isError'      => '',
        'label'        => gTxt('option'),
        'first'        => false,
        'required'     => 1,
        'select_limit' => false,
        'as_csv'       => false,
        'selected'     => '',
        'class'        => 'memSelect',
        'attrs'        => ''
    ), $atts);

    if ($stop === false) {
        trigger_error(gTxt('missing_required_attribute', array('{name}' => 'stop')), E_USER_ERROR);
    }

    $step = empty($latts['step']) ? 1 : assert_int($latts['step']);
    $start = assert_int($latts['start']);
    $stop = assert_int($latts['stop']);

    // fixup start/stop based upon step direction
    $start = $step > 0 ? min($start, $stop) : max($start, $stop);
    $stop = $step > 0 ? max($start, $stop) : min($start, $stop);

    $values = array();

    for ($i = $start; $i >= $start && $i < $stop; $i += $step) {
        array_push($values, $i);
    }

    // intentional trample
    $latts['items'] = $latts['values'] = implode($latts['delimiter'], $values);

    return mem_form_select($latts);
}

function mem_form_select($atts)
{
    global $mem_form_error, $mem_form_submit, $mem_form_default, $mem_form_default_break;

    extract(mem_form_lAtts(array(
        'name'          => '',
        'break'         => $mem_form_default_break,
        'delimiter'     => ',',
        'isError'       => '',
        'label'         => gTxt('option'),
        'items'         => gTxt('mem_form_general_inquiry'),
        'values'        => '',
        'first'         => false,
        'required'      => 1,
        'select_limit'  => false,
        'as_csv'        => false,
        'selected'      => '',
        'class'         => 'memSelect',
        'attrs'         => ''
    ), $atts, false));

    if (empty($name)) {
        $name = mem_form_label2name($label);
    }

    if (!empty($items) && $items[0] == '<') {
        $items = parse($items);
    }

    if (!empty($values) && $values[0] == '<') {
        $values = parse($values);
    }

    if ($first !== false) {
        $items = $first.$delimiter.$atts['items'];
        $values = $first.$delimiter.$atts['values'];
    }

    $select_limit = empty($select_limit) ? 1 : assert_int($select_limit);

    $items = array_map('trim', explode($delimiter, preg_replace('/[\r\n\t\s]+/', ' ',$items)));
    $values = array_map('trim', explode($delimiter, preg_replace('/[\r\n\t\s]+/', ' ',$values)));

    if ($select_limit > 1) {
        $selected = array_map('trim', explode($delimiter, preg_replace('/[\r\n\t\s]+/', ' ',$seelcted)));
    } else {
        $selected = array(trim($selected));
    }

    $use_values_array = (count($items) == count($values));

    if ($mem_form_submit) {
        if (strpos($name, '[]')) {
            $value = ps(substr($name, 0, strlen($name)-2));

            $selected = $value;

            if ($as_csv) {
                $value = implode($delimiter, $value);
            }
        } else {
            $value = trim(ps($name));

            $selected = array($value);
        }

        if (!empty($selected)) {
            if (count($selected) <= $select_limit) {
                foreach ($selected as $v) {
                    $is_valid = ($use_values_array && in_array($v, $values)) or (!$use_values_array && in_array($v, $items));

                    if (!$is_valid) {
                        $invalid_value = $v;
                        break;
                    }
                }

                if ($is_valid) {
                    $isError = false === mem_form_store($name, $label, $value);
                } else {
                    $mem_form_error[] = gTxt('mem_form_invalid_value', array('{label}'=> htmlspecialchars($label), '{value}'=> htmlspecialchars($invalid_value)));
                    $isError = true;
                }
            } else {
                $mem_form_error[] = gTxt('mem_form_invalid_too_many_selected', array(
                        '{label}'=> htmlspecialchars($label),
                        '{count}'=> $select_limit,
                        '{plural}'=> ($select_limit==1 ? gTxt('mem_form_item') : gTxt('mem_form_items'))
                    ));
                $isError = true;
            }
        } elseif ($required) {
            $mem_form_error[] = gTxt('mem_form_field_missing', array('{label}'=> htmlspecialchars($label)));
            $isError = true;
        }
    } elseif (isset($mem_form_default[$name])) {
        $selected = array($mem_form_default[$name]);
    }

    $out = '';

    foreach ($items as $item) {
        $v = $use_values_array ? array_shift($values) : $item;
        $sel = !empty($selected) && in_array($v, $selected);
        $out .= n.t.'<option'.($use_values_array ? ' value="'.$v.'"' : '').($sel ? ' selected="selected">' : '>').
                (strlen($item) ? htmlspecialchars($item) : ' ').'</option>';
    }

    $isError = $isError ? 'errorElement' : '';
    $memRequired = $required ? 'memRequired' : '';
    $class = htmlspecialchars($class);

    $multiple = $select_limit > 1 ? ' multiple="multiple"' : '';

    return '<label for="'.$name.'" class="'.$class.' '.$memRequired.$isError.' '.$name.'">'.htmlspecialchars($label).'</label>'.$break.
        n.'<select id="'.$name.'" name="'.$name.'" class="'.$class.' '.$memRequired.$isError.'"' . $multiple .
            ( !empty($attrs) ? ' ' . $attrs : '').'>'.
            $out.
        n.'</select>';
}

function mem_form_checkbox($atts)
{
    global $mem_form_error, $mem_form_submit, $mem_form_default, $mem_form_default_break;

    extract(mem_form_lAtts(array(
        'break'    => $mem_form_default_break,
        'checked'  => 0,
        'isError'  => '',
        'label'    => gTxt('checkbox'),
        'name'     => '',
        'class'    => 'memCheckbox',
        'required' => 1,
        'attrs'    => ''
    ), $atts));

    if (empty($name)) {
        $name = mem_form_label2name($label);
    }

    if ($mem_form_submit) {
        $value = (bool) ps($name);

        if ($required and !$value) {
            $mem_form_error[] = gTxt('mem_form_field_missing', array('{label}'=> htmlspecialchars($label)));
            $isError = true;
        } else {
            $isError = false === mem_form_store($name, $label, $value ? gTxt('yes') : gTxt('no'));
        }
    } else {
        if (isset($mem_form_default[$name])) {
            $value = $mem_form_default[$name];
        } else {
            $value = $checked;
        }
    }

    $isError = $isError ? 'errorElement' : '';
    $memRequired = $required ? 'memRequired' : '';
    $class = htmlspecialchars($class);

    return '<input type="checkbox" id="'.$name.'" class="'.$class.' '.$memRequired.$isError.'" name="'.$name.'"'.
        ( !empty($attrs) ? ' ' . $attrs : '').
        ($value ? ' checked="checked"' : '').' />'.$break.
        '<label for="'.$name.'" class="'.$class.' '.$memRequired.$isError.' '.$name.'">'.htmlspecialchars($label).'</label>';
}


function mem_form_serverinfo($atts)
{
    global $mem_form_submit;

    extract(mem_form_lAtts(array(
        'label' => '',
        'name'  => ''
    ), $atts));

    if (empty($name)) {
        $name = mem_form_label2name($label);
    }

    if (strlen($name) and $mem_form_submit) {
        if (!$label) {
            $label = $name;
        }

        mem_form_store($name, $label, serverSet($name));
    }
}

function mem_form_secret($atts, $thing = '')
{
    global $mem_form_submit;

    extract(mem_form_lAtts(array(
        'name'  => '',
        'label' => gTxt('secret'),
        'value' => ''
    ), $atts));

    $name = mem_form_label2name($name ? $name : $label);

    if ($mem_form_submit) {
        if ($thing) {
            $value = trim(parse($thing));
        } else {
            $value = trim(parse($value));
        }

        mem_form_store($name, $label, $value);
    }

    return '';
}

function mem_form_hidden($atts, $thing = '')
{
    global $mem_form_submit, $mem_form_default;

    extract(mem_form_lAtts(array(
        'name'         => '',
        'label'        => gTxt('hidden'),
        'value'        => '',
        'isError'      => '',
        'required'     => 1,
        'class'        => 'memHidden',
        'escape_value' => 1,
        'attrs'        => ''
    ), $atts));

    $name = mem_form_label2name($name ? $name : $label);

    if ($mem_form_submit) {
        $value = preg_replace('/^\s*[\r\n]/', '', rtrim(ps($name)));
        $utf8len = preg_match_all("/./su", ltrim($value), $utf8ar);
        $hlabel = htmlspecialchars($label);

        if (strlen($value)) {
            if (!$utf8len) {
                $mem_form_error[] = gTxt('mem_form_invalid_utf8', $hlabel);
                $isError = true;
            } else {
                $isError = false === mem_form_store($name, $label, $value);
            }
        }
    } else {
        if (isset($mem_form_default[$name])) {
            $value = $mem_form_default[$name];
        } elseif ($thing) {
            $value = trim(parse($thing));
        }
    }

    $isError = $isError ? 'errorElement' : '';
    $memRequired = $required ? 'memRequired' : '';

    if ($escape_value) {
        $value = htmlspecialchars($value);
    }

    return '<input type="hidden" class="'.$class.' '.$memRequired.$isError.' '.$name
            . '" name="'.$name.'" value="'.$value.'" id="'.$name.'" '.$attrs.'/>';
}

function mem_form_radio($atts)
{
    global $mem_form_error, $mem_form_submit, $mem_form_values, $mem_form_default, $mem_form_default_break;

    extract(mem_form_lAtts(array(
        'break'   => $mem_form_default_break,
        'checked' => 0,
        'group'   => '',
        'label'   => gTxt('option'),
        'name'    => '',
        'class'   => 'memRadio',
        'isError' => '',
        'attrs'   => '',
        'value'   => false
    ), $atts));

    static $cur_name = '';
    static $cur_group = '';

    if (!$name and !$group and !$cur_name and !$cur_group) {
        $cur_group = gTxt('radio');
        $cur_name = $cur_group;
    }

    if ($group and !$name and $group != $cur_group) {
        $name = $group;
    }

    if ($name) {
        $cur_name = $name;
    } else {
        $name = $cur_name;
    }

    if ($group) {
        $cur_group = $group;
    } else {
        $group = $cur_group;
    }

    $id   = 'q'.md5($name.'=>'.$label);
    $name = mem_form_label2name($name);

    $value = $value === false ? $id : $value;

    if ($mem_form_submit) {
        $is_checked = (ps($name) == $value);

        if ($is_checked or $checked and !isset($mem_form_values[$name])) {
            $isError = false === mem_form_store($name, $group, $value);
        }
    } else {
        if (isset($mem_form_default[$name])) {
            $is_checked = $mem_form_default[$name] == $value;
        } else {
            $is_checked = $checked;
        }
    }

    $class = htmlspecialchars($class);

    $isError = $isError ? ' errorElement' : '';

    return '<input value="'.$value.'" type="radio" id="'.$id.'" class="'.$class.' '.$name.$isError.'" name="'.$name.'"'.
        ( !empty($attrs) ? ' ' . $attrs : '').
        ( $is_checked ? ' checked="checked" />' : ' />').$break.
        '<label for="'.$id.'" class="'.$class.' '.$name.'">'.htmlspecialchars($label).'</label>';
}

function mem_form_submit($atts, $thing='')
{
    global $mem_form_submit;

    extract(mem_form_lAtts(array(
        'button' => 0,
        'label'  => gTxt('save'),
        'name'   => 'mem_form_submit',
        'class'  => 'memSubmit',
    ), $atts));

    $label = htmlspecialchars($label);
    $name = htmlspecialchars($name);
    $class = htmlspecialchars($class);

    if ($mem_form_submit) {
        $value = ps($name);

        if (!empty($value) && $value == $label) {
            // save the clicked button value
            mem_form_store($name, $label, $value);
        }
    }

    if ($button or strlen($thing)) {
        return '<button type="submit" class="'.$class.'" name="'.$name.'" value="'.$label.'">'.($thing ? trim(parse($thing)) : $label).'</button>';
    } else {
        return '<input type="submit" class="'.$class.'" name="'.$name.'" value="'.$label.'" />';
    }
}

function mem_form_lAtts($arr, $atts, $warn=true)
{
    foreach(array('button', 'checked', 'required', 'show_input', 'show_error') as $key) {
        if (isset($atts[$key])) {
            $atts[$key] = ($atts[$key] === 'yes' or intval($atts[$key])) ? 1 : 0;
        }
    }

    if (isset($atts['break']) and $atts['break'] == 'br') {
        $atts['break'] = '<br />';
    }

    return lAtts($arr, $atts, $warn);
}

function mem_form_label2name($label)
{
    $label = trim($label);

    if (strlen($label) == 0) {
        return 'invalid';
    }

    if (strlen($label) <= 32 and preg_match('/^[a-zA-Z][A-Za-z0-9:_-]*$/', $label)) {
        return $label;
    } else {
        return 'q'.md5($label);
    }
}

function mem_form_store($name, $label, $value)
{
    global $mem_form, $mem_form_labels, $mem_form_values;

    $mem_form[$label] = $value;
    $mem_form_labels[$name] = $label;
    $mem_form_values[$name] = $value;

    $is_valid = false !== callback_event('mem_form.store_value', $name);

    // invalid data, unstore it
    if (!$is_valid) {
        mem_form_remove($name);
    }

    return $is_valid;
}

function mem_form_remove($name)
{
    global $mem_form, $mem_form_labels, $mem_form_values;

    $label = $mem_form_labels[$name];

    unset($mem_form_labels[$name], $mem_form[$label], $mem_form_values[$name]);
}

function mem_form_display_error()
{
    global $mem_form_error;

    $out = n.'<ul class="memError">';

    foreach (array_unique($mem_form_error) as $error) {
        $out .= n.t.'<li>'.$error.'</li>';
    }

    $out .= n.'</ul>';

    return $out;
}

function mem_form_value($atts, $thing)
{
    global $mem_form_submit, $mem_form_values, $mem_form_default;

    extract(mem_form_lAtts(array(
        'name'       => '',
        'wraptag'    => '',
        'class'      => '',
        'attributes' => '',
        'id'         => '',
    ), $atts));

    $out = '';

    if ($mem_form_submit) {
        if (isset($mem_form_values[$name])) {
            $out = $mem_form_values[$name];
        }
    } else {
        if (isset($mem_form_default[$name])) {
            $out = $mem_form_default[$name];
        }
    }

    return doTag($out, $wraptag, $class, $attributes, $id);
}

function mem_form_error($err = null)
{
    global $mem_form_error;

    if (!is_array($mem_form_error)) {
        $mem_form_error = array();
    }

    if ($err == null) {
        return !empty($mem_form_error) ? $mem_form_error : false;
    }

    $mem_form_error[] = $err;
}

function mem_form_default($key,$val = null)
{
    global $mem_form_default;

    if (is_array($key)) {
        foreach ($key as $k => $v) {
            mem_form_default($k,$v);
        }

        return;
    }

    $name = mem_form_label2name($key);

    if ($val == null) {
        return (isset($mem_form_default[$name]) ? $mem_form_default[$name] : false);
    }

    $mem_form_default[$name] = $val;

    return $val;
}


function mem_form_mail($from, $reply, $to, $subject, $msg, $content_type = 'text/plain')
{
    global $prefs $production_status;

    $usePhpMailer = false;
    $mail = null;

    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        if ($production_status === 'debug') {
            $mail->SMTPDebug  = 3;
        } elseif ($production_status === 'testing') {
            $mail->SMTPDebug  = 2;
        }

        // Bypass the fact that PHPMailer clashes with <txp:php>.
        $mail::$validator = 'phpinternal';
        $usePhpMailer = true;
    } elseif (!is_callable('mail')) {
        return false;
    }

    if (is_array($to)) {
        $bcc = isset($to['bcc']) ? mem_form_strip($to['bcc']) : '';
        $deliverTo = isset($to['to']) ? mem_form_strip($to['to']) : '';
    } else {
        $bcc = '';
        $deliverTo = mem_form_strip($to);
    }

    // PHPMailer takes care of encoding for us.
    if (!$usePhpMailer) {
        $from = mem_form_strip($from);
        $reply = mem_form_strip($reply);
        $subject =  mem_form_strip($subject);
        $msg = mem_form_strip($msg, false);
    }

    if ($prefs['override_emailcharset'] and is_callable('utf8_decode')) {
        $charset = 'ISO-8859-1';
        $subject = utf8_decode($subject);
        $msg     = utf8_decode($msg);
    } else {
        $charset = 'UTF-8';
    }

    $sep = IS_WIN ? "\r\n" : "\n";

    if ($usePhpMailer) {
        $ret = false;

        try {
//            $mail->IsHTML(true);
//            $mail->SMTPDebug  = 2;
            $smtp_host = get_pref('smtp_host');
            $smtp_user = get_pref('smtp_user');
            $smtp_pass = get_pref('smtp_pass');
            $smtp_port = get_pref('smtp_port');

            if ($smtp_host) {
                $mail->IsSMTP();
                $mail->SMTPAuth = true;
                $mail->Host = $smtp_host;
                $mail->Username = $smtp_user;
                $mail->Password = $smtp_pass;
                $mail->SMTPSecure = 'tls';
                $mail->Port = $smtp_port;
            }

            $mail->addAddress($deliverTo);
            $mail->Subject = $subject;
            $mail->Body    = $msg;
            $mail->CharSet = $charset;

            if ($bcc) {
                $mail->addBCC($bcc);
            }

            if (is_valid_email($from)) {
                $mail->setFrom($from);

                if (is_valid_email($reply)) {
                    $mail->addReplyTo($reply);
                } else {
                    $mail->addReplyTo($from);
                }
            } else {
                // ToDo: Use the site's main (not sub-) domain?
                $mail->addReplyTo('no-reply@example.org');
                $mail->setFrom('no-reply@example.org');
            }

            $ret = $mail->send();

        } catch (PHPMailer\PHPMailer\Exception $e) {
           echo $e->errorMessage();
        } catch (\Exception $e) {
           echo $e->getMessage();
        }

        $mail->clearAllRecipients();
        $mail->clearAttachments();

        return $ret;
    } else {
        $headers = 'From: ' . $from .
            ($bcc ? ($sep . 'Bcc: ' . $bcc) : '') .
            ($reply ? ($sep.'Reply-To: '.$reply) : '') .
            $sep.'X-Mailer: Textpattern (mem_form)' .
            $sep.'X-Originating-IP: '.mem_form_strip((!empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'].' via ' : '').$_SERVER['REMOTE_ADDR']) .
            $sep.'Content-Transfer-Encoding: 8bit' .
            $sep.'Content-Type: '.$content_type.'; charset="'.$charset.'"';

        $subject = mem_form_mailheader($subject, 'text');

        return mail($deliverTo, $subject, $msg, $headers);
    }
}

function mem_form_mailheader($string, $type)
{
    global $prefs;

    if (!strstr($string,'=?') and !preg_match('/[\x00-\x1F\x7F-\xFF]/', $string)) {
        if ("phrase" == $type) {
            if (preg_match('/[][()<>@,;:".\x5C]/', $string)) {
                $string = '"'. strtr($string, array("\\" => "\\\\", '"' => '\"')) . '"';
            }
        } elseif ("text" != $type) {
            trigger_error('Unknown encode_mailheader type', E_USER_WARNING);
        }

        return $string;
    }

    if ($prefs['override_emailcharset']) {
        $start = '=?ISO-8859-1?B?';
        $pcre  = '/.{1,42}/s';
    } else {
        $start = '=?UTF-8?B?';
        $pcre  = '/.{1,45}(?=[\x00-\x7F\xC0-\xFF]|$)/s';
    }

    $end = '?=';
    $sep = IS_WIN ? "\r\n" : "\n";
    preg_match_all($pcre, $string, $matches);

    return $start . join($end.$sep.' '.$start, array_map('base64_encode',$matches[0])) . $end;
}

function mem_form_strip($str, $header = true)
{
    if ($header) {
        $str = strip_rn($str);
    }

    return preg_replace('/[\x00]/', ' ', $str);
}

///////////////////////////////////////////////
// Spam Evaluator
class mem_form_evaluation
{
    var $status;

    function mem_form_evaluation()
    {
        $this->status = 0;
    }

    function add_status($rating = -1)
    {
        $this->status += $rating;
    }

    function get_status()
    {
        return $this->status;
    }

    function is_spam()
    {
        return ($this->status < 0);
    }
}

function &get_mem_form_evaluator()
{
    static $instance;

    if (!isset($instance)) {
        $instance = new mem_form_evaluation();
    }

    return $instance;
}

# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1(#mem_form-plugin). mem_form plugin

h2(#summary). Summary

This plugin provides HTML form capabilities for other plugins. This allows for consistent form tags and behaviors, while reducing overall plugin size and development time.

h2(#author-contact). Author Contact

"Michael Manfre":mailto:mmanfre@gmail.com?subject=Textpattern%20mem_form%20plugin

h2(#license). License

This plugin is licensed under the "GPLv2":http://www.fsf.org/licensing/licenses/info/GPLv2.html.

h2(#tags). Tags

* "mem_form":#mem_form
* "mem_form_checkbox":#mem_form_checkbox
* "mem_form_email":#mem_form_email
* "mem_form_file":#mem_form_file
* "mem_form_hidden":#mem_form_hidden
* "mem_form_radio":#mem_form_radio
* "mem_form_secret":#mem_form_secret
* "mem_form_select":#mem_form_select
* "mem_form_select_category":#mem_form_select_category
* "mem_form_select_range":#mem_form_select_range
* "mem_form_select_section":#mem_form_select_section
* "mem_form_serverinfo":#mem_form_serverinfo
* "mem_form_submit":#mem_form_submit
* "mem_form_text":#mem_form_text
* "mem_form_textarea":#mem_form_textarea
* "mem_form_value":#mem_form_value

h3(#mem_form). mem_form

This tag will create an HTML form and contains all of the processing and validation.

* form string Name of a form that will be parsed to display the form.
* thanks_form string Name of a form that will be parsed upon successful form submission.
* label string Accessible name for the form.
* type string Name of the form to identify itself to bound plugin.
* thanks string Message to display to user upon successful form submission.
* redirect url URL to redirect upon successful form submission. Overrides “thanks” and “thanks_form”.
* redirect_form string Name of a form that will be parsed as displayed to the user on a redirect. The string “_{uri}_” will be replaced with the redirect url.
* enctype string HTML encoding type used when the form is submitted. @enctype="multipart/form-data"@ is required when using mem_form_file.
* default_break string Separator between label tag and input tag to be used as the default for every mem_form compatible field contained in the form. Default is @<br>@

h3(#mem_form_checkbox). mem_form_checkbox

This will output an HTML checkbox field.

* break string Separator between label tag and input tag.
* checked int Is this box checked. Default “0”.
* label string Friendly name for the input field. If set, this will output an HTML @<label>@ tag linked to the input field.
* name string Input field name.
* required int Specifies if input is required.
* class string CSS class name.

h3(#mem_form_email). mem_form_email

This will output an HTML text input field and validates the submitted value as an email address.

* break string Separator between label tag and input tag.
* label string Friendly name for the input field. If set, this will output an HTML @<label>@ tag linked to the input field.
* name string Input field name.
* required int Specifies if input is required.
* class string CSS class name.
* default string The default value.
* max int Max character length.
* min int Min character length.
* size int Size of input field.

h3(#mem_form_file). mem_form_file

&#43;p(tag&#45;summary). This will output an HTML file input field. You must add the @enctype="multipart/form-data"@ attribute to your enclosing mem_form for this to work.

* label string Friendly name for the input field. If set, this will output an HTML @<label>@ tag linked to the input field.
* name string Input field name.
* class string CSS class name.
* break string Separator between label tag and input tag.
* no_replace int Specifies whether a user can upload another file and replace the existing file that will be submitted on successful completion of the form. If “1”, the file input field will be replaced with details about the already uploaded file.
* required int Specifies if input is required.
* size int Size of input field.
* max_file_size int Maximum size for the uploaded file. Checked server&#45;side.
* accept string The HTML file input field's “accept” argument that specifies which file types the field should permit.

h3(#mem_form_hidden). mem_form_hidden

This will output an HTML hidden text input field.

* label string Friendly name for the input field. If set, this will output an HTML @<label>@ tag linked to the input field.
* name string Input field name.
* value string The input value.
* required int Specifies if input is required.
* class string CSS class name.
* escape_value int Set to “0” to prevent html escaping the value. Default “1”.

h3(#mem_form_radio). mem_form_radio

This will output an HTML radio button.

* break string Separator between label tag and input tag.
* label string Friendly name for the input field. If set, this will output an HTML @<label>@ tag linked to the input field.
* name string Input field name.
* class string CSS class name.
* group string A name that identifies a group of radio buttons.
* value string The value of the radio button. If not set, a unique value is generated.
* checked int Is this box checked. Default “0”.

h3(#mem_form_secret). mem_form_secret

This will output nothing in HTML and is meant to pass information to the sumbit handler plugins.

* label string Friendly name for the input field. If set, this will output an HTML @<label>@ tag linked to the input field.
* name string Input field name.
* value string The input value.

h3(#mem_form_select). mem_form_select

This will output an HTML select field.

* label string Friendly name for the input field. If set, this will output an HTML @<label>@ tag linked to the input field.
* name string Input field name.
* break string Separator between label tag and input tag.
* delimiter string List separator. Default “,”
* items string Delimited list containing a select list display values.
* values string Delimited list containing a select list item values.
* required int Specifies if input is required.
* selected string The value of the selected item.
* first string Display value of the first item in the list. E.g. “Select a Section” or “” for a blank option.
* class string CSS class name.
* select_limit int Specifies the maximum number of items that may be selected. If set to a value greater than 1, a multiselect will be used. The stored value will be an array.
* as_csv int If set to 1, the value will be stored as a delimited string of values instead of an array. This does nothing when select_limit is less than 2.

h3(#mem_form_select_category). mem_form_select_category

This will output an HTML select field populated with the specified Textpattern categories.

* label string Friendly name for the input field. If set, this will output an HTML @<label>@ tag linked to the input field.
* name string Input field name.
* break string Separator between label tag and input tag.
* delimiter string List separator. Default “,”
* items string Delimited list containing a select list display values.
* values string Delimited list containing a select list item values.
* required int Specifies if input is required.
* selected string The value of the selected item.
* first string Display value of the first item in the list. E.g. “Select a Section” or “” for a blank option.
* class string CSS class name.
* exclude string List of item values that will not be included.
* sort string How will the list values be sorted.
* type string Category type name. E.g. “article”

h3(tag#mem_form_select_range) . mem_form_select_range

This will output an HTML select field populated with a range of numbers.

* start int The initial number to include. Default is 0.
* stop int The largest/smallest number to include.
* step int The increment between numbers in the range. Default is 1.
* label string Friendly name for the input field. If set, this will output an HTML @<label>@ tag linked to the input field.
* name string Input field name.
* break string Separator between label tag and input tag.
* delimiter string List separator. Default “,”
* items string Delimited list containing a select list display values.
* values string Delimited list containing a select list item values.
* required int Specifies if input is required.
* selected string The value of the selected item.
* first string Display value of the first item in the list. E.g. “Select a Section” or “” for a blank option.
* class string CSS class name.
* exclude string List of item values that will not be included.
* sort string How will the list values be sorted.
* type string Category type name. E.g. “article”

h3(#mem_form_select_section). mem_form_select_section

This will output an HTML select field populated with the specified Textpattern sections.

* label string Friendly name for the input field. If set, this will output an HTML @<label>@ tag linked to the input field.
* name string Input field name.
* break string Separator between label tag and input tag.
* delimiter string List separator. Default “,”
* items string Delimited list containing a select list display values.
* values string Delimited list containing a select list item values.
* required int Specifies if input is required.
* selected string The value of the selected item.
* first string Display value of the first item in the list. E.g. “Select a Section” or “” for a blank option.
* class string CSS class name.
* exclude string List of item values that will not be included.
* sort string How will the list values be sorted.

h3(#mem_form_serverinfo). mem_form_serverinfo

This will output no HTML and is used to pass server information to the plugin handling the form submission.

* label string Friendly name for the input field. If set, this will output an HTML @<label>@ tag linked to the input field.
* name string Input field name.

h3(#mem_form_submit). mem_form_submit

This will output either an HTML submit input field or an HTML button.

* label string Friendly name for the input field. If set, this will output an HTML @<label>@ tag linked to the input field.
* name string Input field name.
* class string CSS class name.
* button int If “1”, an html button tag will be used instead of an input tag.

h3(#mem_form_text). mem_form_text

This will output an HTML text input field.

* label string Friendly name for the input field. If set, this will output an HTML @<label>@ tag linked to the input field.
* name string Input field name.
* class string CSS class name.
* break string Separator between label tag and input tag.
* default string The default value.
* format string A regex pattern that will be matched against the input value. You must escape all backslashes ‘\'. E.g “/\\d/” is a single digit.
* example string An example of a correctly formatted input value.
* password int Specifies if the input field is a password field.
* required int Specifies if input is required.
* max int Max character length.
* min int Min character length.
* size int Size of input field.
* escape_value int Set to “0” to prevent html escaping the value. Default “1”.

h3(#mem_form_textarea). mem_form_textarea

This will output an HTML textarea.

* label string Friendly name for the input field. If set, this will output an HTML @<label>@ tag linked to the input field.
* name string Input field name.
* class string CSS class name.
* break string Separator between label tag and input tag.
* default string The default value.
* max int Max character length.
* min int Min character length.
* required int Specifies if input is required.
* rows int Number of rows in the textarea.
* cols int Number of columns in the textarea.
* escape_value int Set to “0” to prevent html escaping the value. Default “1”.

h3(#mem_form_value). mem_form_value

This will output the value associated with a form field. Useful to mix HTML input fields with mem_form.

* id string ID for output wrap tag.
* class string CSS class name.
* class string CSS class.
* wraptag string HTML tag to wrap around the value.
* attributes string Additional HTML tag attributes that should be passed to the output tag.

h2(#exposed-functions). Exposed Functions

h3(#mem_form_mail). mem_form_mail

This will send an email message.

* Return Value bool Returns true or false, indicating whether the email was successfully given to the mail system. This does not indicate the validity of the email address or that the recipient actually received the email.
* from string The From email address.
* reply string The Reply To email address.
* to string The To email address(es).
* subject string The email's Subject.
* msg string The email message.

h3(#mem_form_error). mem_form_error

This will set or get errors associated with the form.

* Return Value mixed If err is null, then it will return an array of errors that have been set.
* err string An error that will be added to the list of form errors that will be displayed to the form user.

h3(#mem_form_default). mem_form_default

This will get or set a default value for a form.

* Return Value mixed If val is null, then it will return the default value set for the input field matching %(atts&#45;name)key. If key does not exist, then it will return false.
* key string The name of the input field.
* val string If specified, this will be specified as the default value for the input field named “key”.

h3(#mem_form_store). mem_form_store

This will store the name, label and value for a field in to the appropriate global variables.

* name string The name of the field.
* label string The label of the field.
* value mixed The value of the field.

h3(#mem_form_remove). mem_form_remove

This will remove the information associated with a field that has been stored.

* name string The name of the field.

h2(#global-variables). Global Variables

This library allows other plugins to hook in to events with the @register_callback@ function.

* $mem_form_type string A text value that allows a plugin determine if it should process the current form.
* $mem_form_submit bool This specifies if the form is doing a postback.
* $mem_form_default array An array containing the default values to use when displaying the form.
* $mem_form array An array mapping all input labels to their values.
* $mem_form_labels array An array mapping all input names to their labels.
* $mem_form_values array An array mapping all input names to their values.
* $mem_form_thanks_form string Contains the message that will be shown to the user after a successful submission. Either the “thanks_form” or the “thanks” attribute. A plugin can modify this value or return a string to over

h2(#plugin-events). Plugin Events

h3. mem_form.defaults

Allows a plugin to alter the default values for a form prior to being displayed.

h3. mem_form.display

Allows a plugin to insert additional html in the rendered html form tag.

h3. mem_form.submit

Allows a plugin to act upon a successful form submission.

h3. mem_form.spam

Allows a plugin to test a submission as spam. The function get_mem_form_evaluator() returns the evaluator.

h3. mem_form.store_value

On submit, this event is called for each field that passed the builtin checks and was just stored in to the global variables. The callback step is the field name. This callback can be used for custom field validation. If the value is invalid, return false. Warning: This event is called for each field even if a previously checked field has failed.

h3. mem_form.validate

This event is called on form submit, after the individual fields are parsed and validated. This event is not called if there are any errors after the fields are validated. Any multi&#45;field or form specific validation should happen here. Use mem_form_error() to set any validation error messages to prevent a successful post.

# --- END PLUGIN HELP ---
-->
<?php
}
?>