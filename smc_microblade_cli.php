#!/usr/bin/php

<?php

define("COOKIE_FILE", "cookie.txt");

main();

function main() {
    // Host:Username:Password:Type:Command:Subcommand
    $options = getopt("H:U:P:T:C:N:S:h:");

    if (!(($options["H"]) && ($options["U"]) && ($options["P"]))) {
        Usage('general');
        exit;
    }

    // Test CMM can be connected
    if (!ping($options["H"])) {
        print "Error: CMM Host " . $options["H"] . " CANNOT CONNECT, PLEASE CHECK!!\n";
        exit;
    }

    // Show Help
    if ($options["h"]) {
        print Usage("general");
        return;
    }

    // CMM info
    $ipmi_host = $options["H"];
    $ipmi_user = $options["U"];
    $ipmi_pass = $options["P"];

    // Command Type
    $type = $options["T"];

    // Commands
    $command = $options["C"];
    // Subcommands
    $subcommand = $options["S"];

    // On which node (maybe multiple, esp on blades)
    $nodes = $options["N"];

    // Login, to fetch session cookie
    Login($ipmi_host, $ipmi_user, $ipmi_pass);

    // Detect Model Type
    // Current we have 2 models, MBI-6118-D and MBI-6219-G
    $model_type = ModelType($ipmi_host);

    //
    // Commands
    //  PSU
    //      status / detail / poweron / poweroff
    //  Switch
    //      status / detail / poweron / poweroff / hwreset / reboot / resetdefault / uidon / uidoff
    //  CMM
    //      status / detail
    //  Blade
    //      status / detail / poweron / poweroff / powercycle / powerreset / gracefulshutdown / accycle / pwfailpolicy / aclostpolicy / bmcreset / bmcdefault / uidon / uidoff
    //

    $result = '';

    switch ($type) {
        case 'psu':
            $result = PSUCommands($ipmi_host, $model_type, $command, $nodes);
            break;
        case 'switch':
            $result = SwitchCommands($ipmi_host, $model_type, $command, $nodes);
            break;
        case 'cmm':
            $result = CMMCommands($ipmi_host, $model_type, $command);
            break;
        case 'blade':
            $result = BladeCommands($ipmi_host, $model_type, $command, $nodes, $subcommand);
            break;
        default:
            $result = Usage('general');
            break;
    }

    print "$result\n";

    Logout();
}

// Login the user
function Login($ipmi_host, $ipmi_user, $ipmi_pass) {
    $ch = curl_init('http://' . $ipmi_host . '/cgi/login.cgi');
    curl_setopt($ch, CURLOPT_COOKIEJAR, COOKIE_FILE);
    curl_setopt($ch, CURLOPT_COOKIESESSION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'name=' . $ipmi_user . '&pwd=' . $ipmi_pass);
    curl_exec($ch);
    curl_close($ch);

    return 0;
}

// Logout 
function Logout() {
    unlink(COOKIE_FILE);

    return 0;
}

// Get Model
function ModelType($ipmi_host) {
    $ch = curl_init('http://' . $ipmi_host . '/cgi/ipmi.cgi');
    curl_setopt($ch, CURLOPT_COOKIEJAR, COOKIE_FILE);
    curl_setopt($ch, CURLOPT_COOKIEFILE, COOKIE_FILE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'GET_BLADE_INFO.XML=(0,0)');
    $content = curl_exec($ch);
    curl_close($ch);

    if (preg_match('/6118/', $content)) {
        return 6118;
    } elseif (preg_match('/6219/', $content)) {
        return 6219;
    }

    return 0;
}

// PSU Commands
function PSUCommands($ipmi_host, $model_type, $command = 'status', $psu = NULL) {
    $ch = curl_init('http://' . $ipmi_host . '/cgi/ipmi.cgi');
    curl_setopt($ch, CURLOPT_COOKIEJAR, COOKIE_FILE);
    curl_setopt($ch, CURLOPT_COOKIEFILE, COOKIE_FILE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);

    if (('detail' == $command) || ('status' == $command)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'PSU_INFO.XML=(0,0)');
        $content = curl_exec($ch);
        curl_close($ch);

        $tmp = preg_split('/[="|"\s]/', $content);
        $result = '';

        if (6118 == $model_type) {
            // MBI-6118D: 8 PSUs
            $psu_index = ["A1", "A2", "A3", "A4", "B1", "B2", "B3", "B4"];
            for ($i = 0; $i < 8; $i++) {
                if ('detail' == $command) {
                    // Format:   PSU-[A-B][1-4],
                    //           Model,PowerStatus,Temperature,
                    //           FanSpeed,Fxx,MaxWatt,
                    //           AC_Input_Current,DC_Output_Current,Current_PowerUsage,
                    //           FW_Version,FRU_Version,AC_Input_Voltage
                    $result = $result . sprintf("PSU-%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                                                $psu_index[$i],
                                                $tmp[$i*49+15], $tmp[$i*49+20], $tmp[$i*49+24],
                                                $tmp[$i*49+28], $tmp[$i*49+32], $tmp[$i*49+36],
                                                $tmp[$i*49+40], $tmp[$i*49+44], $tmp[$i*49+48],
                                                $tmp[$i*49+52], $tmp[$i*49+56], $tmp[$i*49+60]);
                } elseif ('status' == $command) {
                    // Format:PSU-[A-B][1-4],PowerStatus (On / Off), Current_PowerUsage
                    $result = $result . "PSU-" . $psu_index[$i] . ",";
                    $power_status = $tmp[$i*49+24] ? "On," : "Off,";
                    $error_status = preg_match('/nan/', $tmp[$i*49+52]) ? "Error\n" : "Normal\n";
                    $result = $result . $power_status . $error_status;
                }
            }
        } elseif (6219 == $model_type) {
            // MBI-6219G: 4 PSUs
            $psu_index = ["A1", "A2", "B1", "B2"];
            for ($i = 0; $i < 4; $i++) {
                if ('detail' == $command) {
                    // Format:   PSU-[A-B][1-2],
                    //           Model,PowerStatus,Temperature,
                    //           FanSpeed,Fxx,MaxWatt,
                    //           AC_Input_Current,DC_Output_Current,Current_PowerUsage,
                    //           FW_Version,FRU_Version,AC_Input_Voltage
                    $result = $result . sprintf("PSU-%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                                                $psu_index[$i],
                                                $tmp[$i*48+15], $tmp[$i*48+19], $tmp[$i*48+23],
                                                $tmp[$i*48+27], $tmp[$i*48+31], $tmp[$i*48+35],
                                                $tmp[$i*48+39], $tmp[$i*48+43], $tmp[$i*48+47],
                                                $tmp[$i*48+51], $tmp[$i*48+55], $tmp[$i*48+59]);
                } elseif ('status' == $command) {
                    // Format:PSU-[A-B][1-2],PowerStatus (On / Off), Current_PowerUsage
                    $result = $result . "PSU-" . $psu_index[$i] . ",";
                    $power_status = $tmp[$i*48+23] ? "On," : "Off,";
                    $error_status = preg_match('/nan/', $tmp[$i*48+51]) ? "Error\n" : "Normal\n";
                    $result = $result . $power_status . $error_status;
                }
            }
        }
        return $result;
    }

    //
    // PSU Commands: 1 PSU, 1 Command
    // ({1,2,4,8,10,20,40,80}, # 1,2,4,8,16,32,64,128
    //     1 # Power On
    //     0 # Power Off
    // )
    //
    // * Example:
    //      if ('test' == $command) {
    //          curl_setopt($ch, CURLOPT_POSTFIELDS, 'PSU_BTN_ACTION.XML=(80,1)&_=');
    //          curl_exec($ch);
    //          curl_close($ch);
    //      }
    //

    if ((preg_match('/^poweron$|^poweroff$/', $command)) && (!empty($psu)) && (preg_match('/^[A-B][1-4]$/', $psu))) {
        if (6219 == $model_type) {
            return "Error: MBI-6219-G does not support poweron/poweroff commands.\n";
        }

        $result = '';

        // Hardcore
        $psu_index = [
            'A1' => '1',
            'A2' => '2',
            'A3' => '4',
            'A4' => '8',
            'B1' => '10',
            'B2' => '20',
            'B3' => '40',
            'B4' => '80',
        ];
        $psu_opcode = '';

        if ('poweron' == $command) {
            $psu_opcode = '1';
            $result = 'Powered On';
        } elseif ('poweroff' == $command) {
            $psu_opcode = '0';
            $result = 'Powered Off';
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, 'PSU_BTN_ACTION.XML=(' . $psu_index[$psu] . ',' . $psu_opcode . ')&_=');

        if (false === curl_exec($ch)) {
            $result = "Error: " . curl_error($ch);
        } else {
            $result = $result . " on PSU $psu, at CMM: $ipmi_host, successfully.\n";
        }
    } else {
        $result = Usage('psu');
    }

    return $result;
}

// Switch commands
function SwitchCommands ($ipmi_host, $model_type, $command = 'status', $switch = NULL) {
    $ch = curl_init('http://' . $ipmi_host . '/cgi/ipmi.cgi');
    curl_setopt($ch, CURLOPT_COOKIEJAR, COOKIE_FILE);
    curl_setopt($ch, CURLOPT_COOKIEFILE, COOKIE_FILE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);

    if (('detail' == $command) || ('status' == $command)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'SWITCH_INFO.XML=(0,0)');
        $content = curl_exec($ch);
        curl_close($ch);

        $tmp = preg_split('/[="|"\s]/', $content);
        $result = '';

        // MBI-6118D: 2 Switches
        for ($i = 0; $i < 2; $i++) {
            if ('detail' == $command) {
                // Format:  SWITCH-[0-2],
                //          Model_Type,Power_Status,
                //          Errors,Switch_IP,BMC_HTTP_OK
                if (6118 == $model_type) {
                    $bmc_http_ok = ping($tmp[$i*66+77]) ? 'BMC_HTTP_OK' : 'BMC_HTTP_Error';
                    $result = $result . sprintf("SWITCH-%d,%s,%s,%s,%s,%s\n",
                                                $i,
                                                $tmp[$i*66+20], $tmp[$i*66+25],
                                                $tmp[$i*66+41], $tmp[$i*66+77], $bmc_http_ok);
                } elseif (6219 == $model_type) {
                    $bmc_http_ok = ping($tmp[$i*74+77]) ? 'BMC_HTTP_OK' : 'BMC_HTTP_Error';
                    $result = $result . sprintf("SWITCH-%d,%s,%s,%s,%s,%s\n",
                                                $i,
                                                $tmp[$i*74+28], $tmp[$i*74+33],
                                                $tmp[$i*74+41], $tmp[$i*74+77], $bmc_http_ok);
                }
            } elseif ('status' == $command) {
                $result = $result . "SWITCH-" . $i . ",";

                if (6118 == $model_type) {
                    $power_status = $tmp[$i*66+25] ? "On," : "Off,";
                    $error_status = $tmp[$i*66+41] ? "Error\n" : "Normal\n";
                } elseif (6219 == $model_type) {
                    $power_status = $tmp[$i*74+33] ? "On," : "Off,";
                    $error_status = $tmp[$i*74+41] ? "Error\n" : "Normal\n";
                }

                $result = $result . $power_status . $error_status;
            }
        }
        return $result;
    }

    //
    // Switch Commands: 1 switch, 1 command
    // ([1-2],
    //     1 # Power On
    //     0 # Power Off
    //     3 # HW Reset
    //     1d # Graceful Reboot
    //     19 # Reset to Default
    //     7 # UID On
    //     8 # UID Off
    // )
    //
    // * Example:
    //      if ('test' == $command) {
    //          curl_setopt($ch, CURLOPT_POSTFIELDS, 'SWITCH_BTN_ACTION.XML=(1,7)&_=');
    //          curl_exec($ch);
    //          curl_close($ch);
    //      }
    //

    if ((preg_match('/^poweron$|^poweroff$|^hwreset$|^reboot$|^resetdefault$|^uidon$|^uidoff$/', $command)) && 
        (!empty($switch)) && (preg_match('/^A[1-2]$/', $switch))) {
        $result = '';
        $switch_index = substr($switch, -1);
        $switch_opcode = '';

        switch ($command) {
            case 'poweron':
                $switch_opcode = '1';
                $result = 'Powered On';
                break;
            case 'poweroff':
                $switch_opcode = '0';
                $result = 'Powered Off';
                break;
            case 'hwreset':
                $switch_opcode = '3';
                $result = 'HW Reset';
                break;
            case 'reboot':
                $switch_opcode = '1d';
                $result = 'Gracefully Rebooted';
                break;
            case 'resetdefault':
                $switch_opcode = '19';
                $result = 'Reset to Default';
                break;
            case 'uidon':
                $switch_opcode = '7';
                $result = 'Set UID On';
                break;
            case 'uidoff':
                $switch_opcode = '8';
                $result = 'Set UID Off';
                break;
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, 'SWITCH_BTN_ACTION.XML=(' . $switch_index . ',' . $switch_opcode . ')&_=');
        if (false === curl_exec($ch)) {
            $result = "Error: " . curl_error($ch);
        } else {
            $result = $result . " on Switch $switch, at CMM: $ipmi_host, successfully.\n";
        }
    } else {
        $result = Usage('switch');
    }

    return $result;
}

// CMM Commands
function CMMCommands ($ipmi_host, $model_type, $command = 'status') {
    $ch = curl_init('http://' . $ipmi_host . '/cgi/ipmi.cgi');
    curl_setopt($ch, CURLOPT_COOKIEJAR, COOKIE_FILE);
    curl_setopt($ch, CURLOPT_COOKIEFILE, COOKIE_FILE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'GENERIC_INFO.XML=(0,0)');
    $content = curl_exec($ch);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'GET_CMM_Info.XML=&_=');
    $content2 = curl_exec($ch);
    curl_close($ch);

    $tmp = preg_split('/[="|"\s]/', $content);
    $tmp2 = preg_split('/[="|"\s]/', $content2);
    $result = '';

    $cmm_status = $tmp2[15] ? [$tmp2[43], $tmp2[39]] : [$tmp2[39], $tmp2[43]];

    if ('detail' == $command) {
        $result = "#CMM-Active/Redundant,CMM_Slot_Location,CMM_IP,CMM_MAC_Address,CMM_FW_Version,CMM_Status\n";
        // Format: CMM-Active/Redundant, CMM_Slot_Location, CMM_IP, CMM_MAC_Address, CMM_FW_Version, CMM_Status
        $result = $result . sprintf("CMM-Active,%s,%s,%s,%s,%s\n",
                                    $tmp[41], $tmp[13], $tmp[17], $tmp2[27], $cmm_status[0]);
        $result = $result . sprintf("CMM-Redundant,%s,%s,%s,%s,%s\n",
                                    $tmp[45], $tmp[13], $tmp[57], $tmp2[31], $cmm_status[1]);
    } elseif ('status' == $command) {
        ($cmm_status[0]) ? $cmm_status_active = 'Error' : $cmm_status_active = 'Normal';
        ($cmm_status[1]) ? $cmm_status_redundant = 'Error' : $cmm_status_redundant = 'Normal';
        $result = $result . sprintf("CMM-Active,%s,CMM_Slot=%s,%s\n",
                                    $tmp[13], $tmp[41], $cmm_status_active);
        $result = $result . sprintf("CMM-Redundant,%s,CMM_Slot=%s,%s\n",
                                    $tmp[13], $tmp[45], $cmm_status_redundant);
    } else {
        $result = Usage('cmm');
    }

    return $result;
}

// Blade Commands
function BladeCommands ($ipmi_host, $model_type, $command = 'status', $blade = NULL, $subcommand = NULL) {
    $data = [];
    $tmp = [];

    if (('detail' == $command) || ('status' == $command)) {
        $result = '';

        // Fetch Node Status using multiRequest, maybe 20 secs for all.
        if (6118 == $model_type) {
            for ($i = 0; $i < 28; $i++) {
                $data[$i]['url'] = 'http://' . $ipmi_host . '/cgi/ipmi.cgi';
                $data[$i]['post']['GET_NODE_MB_INFO.XML'] = '(' . $i . ',0)';
                $data[$i+28]['url'] = 'http://' . $ipmi_host . '/cgi/ipmi.cgi';
                $data[$i+28]['post']['GET_NETWORK_CONFIG.XML'] = '(' . dechex($i) . ',0)';
            }
        } elseif (6219 == $model_type) {
            for ($i = 0; $i < 14; $i++) {
                for ($j = 0; $j < 2; $j++) {
                    $data[$i*2+$j]['url'] = 'http://' . $ipmi_host . '/cgi/ipmi.cgi';
                    $data[$i*2+$j]['post']['GET_NODE_MB_INFO.XML'] = '(' . $i . ',' . $j .')';
                    $data[$i*2+$j+28]['url'] = 'http://' . $ipmi_host . '/cgi/ipmi.cgi';
                    $data[$i*2+$j+28]['post']['GET_NETWORK_CONFIG.XML'] = '(' . dechex($i) . ',' . $j . ')';
                }
            }
        }

        $blade_status = multiRequest($data);

        for ($i = 0; $i < 28 * 2; $i++) {
            $tmp[$i] = preg_split('/[="|"\s]/', $blade_status[$i]);
        }

        if ('detail' == $command) {
            if (6118 == $model_type) {
                $result = "#Blade-[A-B][1-14],Product_Name,Model_Name,Power_Status,Error,MAC1,MAC2,BMC_MAC,BMC_IP,BMC_MASK,BMC_GATEWAY,BMC_DHCP_ENABLE,BMC_MII_SPEED,BMC_OPERATION_STATE,BMC_VERSION,Current_Power,Post_Code,BMC_HTTP_STATUS\n";
                for ($i = 0; $i < 28; $i++) {
                    // Format:  Blade-[A-B][1-14],
                    //          Product_Name, Model_Name, Power_Status,
                    //          Error, MAC1, MAC2,
                    //          BMC_MAC, BMC_IP, BMC_MASK,
                    //          BMC_GATEWAY, BMC_DHCP_ENABLE, BMC_MII_SPEED,
                    //          BMC_OPERATION_STATE, BMC_VERSION, Current_Power, Post_Code

                    $node_index = '';

                    if ($i < 14) {
                        $node_index = sprintf("A%d", $i + 1);
                    } elseif (($i >= 14) && ($i < 28)) {
                        $node_index = sprintf("B%d", $i - 13);
                    }

                    $bmc_http_status = ping($tmp[$i+28][127]) ? "BMC_HTTP_OK" : "BMC_HTTP_ERROR";

                    $result = $result . sprintf("Blade-%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                                                $node_index,
                                                $tmp[$i][92], $tmp[$i][87], $tmp[$i][96],
                                                $tmp[$i][112], $tmp[$i][74], $tmp[$i][78],
                                                $tmp[$i+28][131], $tmp[$i+28][135], $tmp[$i+28][139],
                                                $tmp[$i+28][151], $tmp[$i+28][164], $tmp[$i+28][168],
                                                $tmp[$i][132], $tmp[$i][128], $tmp[$i][124], $bmc_http_status);
                }
            } elseif (6219 == $model_type) {
                $result = "#Blade-A[1-14]-Node-[1-2],Product_Name,Model_Name,Power_Status,Error,MAC1,MAC2,BMC_MAC,BMC_IP,BMC_MASK,BMC_GATEWAY,BMC_DHCP_ENABLE,BMC_MII_SPEED,BMC_OPERATION_STATE,BMC_VERSION,Current_Power,Post_Code,BMC_HTTP_OK\n";
                for ($i = 0; $i < 28; $i++) {
                    // Format:  Blade-A[1-14]-Node-[1-2],
                    //          Product_Name, Model_Name, Power_Status,
                    //          Error, MAC1, MAC2,
                    //          BMC_MAC, BMC_IP, BMC_MASK,
                    //          BMC_GATEWAY, BMC_DHCP_ENABLE, BMC_MII_SPEED,
                    //          BMC_OPERATION_STATE, BMC_VERSION, Current_Power, Post_Code, BMC_HTTP_OK

                    $node_index = (int)($i / 2);
                    $node_slot_index = (int)($i % 2);
                    $bmc_http_status = ping($tmp[$i+28][127]) ? "BMC_HTTP_OK" : "BMC_HTTP_ERROR";

                    $result = $result . sprintf("Blade-%s-Node-%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                                                $node_index, $node_slot_index,
                                                $tmp[$i][92], $tmp[$i][87], $tmp[$i][96],
                                                $tmp[$i][112], $tmp[$i][74], $tmp[$i][78],
                                                $tmp[$i+28][131], $tmp[$i+28][127], $tmp[$i+28][135],
                                                $tmp[$i+28][139], $tmp[$i+28][151], $tmp[$i+28][164],
                                                $tmp[$i][137], $tmp[$i][133], $tmp[$i][129], $bmc_http_status);
                }
            }
        } elseif ('status' == $command) {
            if (6118 == $model_type) {
                $result = "#Blade-[A-B][1-14],Power_Status,Error,BMC_IP,BMC_MII_SPEED,BMC_OPERATION_STATE,BMC_HTTP_OK\n";
                for ($i = 0; $i < 28; $i++) {
                    // Format: Blade-[A-B][1-14], Power_Status, Error, BMC_IP, BMC_MII_SPEED, BMC_OPERATION_STATE, BMC_HTTP_OK
                    $node_index = (($i >= 14) && ($i < 28)) ? sprintf("B%d", $i - 13) : sprintf("A%d", $i + 1);

                    $power_status = $tmp[$i][96] ? 'On' : 'Off';
                    $error_status = $tmp[$i][112] ? 'Error' : 'Normal';
                    $bmc_http_status = ping($tmp[$i+28][127]) ? "BMC_HTTP_OK" : "BMC_HTTP_ERROR";
                    $bmc_mii_speed = ($tmp[$i+28][164] == 2) ? '1Gbps' : '100Mbps';
                    $bmc_operation_state = $tmp[$i+28][168] ? 'Normal' : 'Error';
                    $result = $result . sprintf("Blade-%s,%s,%s,%s,%s,%s,%s\n",
                                                $node_index,
                                                $power_status, $error_status, $tmp[$i+28][127],
                                                $bmc_mii_speed, $bmc_operation_state, $bmc_http_status);
                }
            } elseif (6219 == $model_type) {
                $result = "#Blade-A[1-14]-Node-[1-2],Power_Status,Error,BMC_IP,BMC_MII_SPEED,BMC_OPERATION_STATE,BMC_HTTP_OK\n";
                for ($i = 0; $i < 28; $i++) {
                    // Format: Blade-[A-B][1-14], Power_Status, Error, BMC_IP, BMC_MII_SPEED, BMC_OPERATION_STATE, BMC_HTTP_OK
                    $node_index = (int)($i / 2);
                    $node_slot_index = (int)($i % 2);

                    $power_status = $tmp[$i][96] ? 'On' : 'Off';
                    $error_status = $tmp[$i][112] ? 'Error' : 'Normal';
                    $bmc_http_status = ping($tmp[$i+28][127]) ? "BMC_HTTP_OK" : "BMC_HTTP_ERROR";
                    $bmc_mii_speed = ($tmp[$i+28][164] == 2) ? '1Gbps' : '100Mbps';
                    $bmc_operation_state = $tmp[$i+28][168] ? 'BMC_Normal' : 'BMC_Error';
                    $result = $result . sprintf("Blade-A%s-Node-%s,%s,%s,%s,%s,%s,%s\n",
                                                $node_index, $node_slot_index,
                                                $power_status, $error_status, $tmp[$i+28][127],
                                                $bmc_mii_speed, $bmc_operation_state, $bmc_http_status);
                }
            }
        }
        return $result;
    }

    //
    // Blade Commands: 1 blade node, 1 command
    // ((1|[1-9]01|[a-f]01|1[0-9]01|1[a-b]01),
    //     1 # Power On
    //     0 # Power Off
    //     2 # Power Cycle
    //     3 # HW Reset
    //     5 # Graceful Shutdown
    //     16 # BMC Reset
    //     17 # BMC Reset to Default
    //     7 # UID On
    //     8 # UID Off
    // )
    //
    // * Example
    //
    //      if ('test' == $command) {
    //          curl_setopt($ch, CURLOPT_POSTFIELDS, 'BLADE_BTN_ACTION.XML=(1,7)&_=');
    //          curl_exec($ch);
    //          curl_close($ch);
    //      }
    //
    // Blade Commands: Multiple blade nodes
    //
    // * BladeSet: 1 node, 1 bit
    // * NodeSet: 1 node, 4 bit
    // * RequestAction
    //  * PowerOn: 1
    //  * PowerOff: 0
    //  * PowerCycle: 2
    //  * PowerReset: 3
    //  * Graceful Shutdown: 5
    //  * AC Cycle: 24
    //  * PowerFail Policy: 10
    //  * ACLost Policy: 9
    //
    // * Example
    //
    //      if ('test' == $command) {
    //          curl_setopt($ch, CURLOPT_URL, 'http://' . $ipmi_host . '/cgi/blade_status.cgi'); 
    //          curl_setopt($ch, CURLOPT_POSTFIELDS, 'RequestAction=1&BladeSet=1000000000000000000000000000&NodeSet=1000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000&_=');
    //          curl_exec($ch);
    //          curl_close($ch);
    //      }

    if ((preg_match('/^poweron$|^poweroff$|^powercycle$|^powerreset$|^gracefulshutdown$|^accycle$|^pwfailpolicy$|^aclostpolicy$|^powercapping$|^uidon$|^uidoff$|^bmcreset$|^bmcdefault$/', $command)) && (!empty($blade))) {
        $blade_bladeset_index = [];
        $blade_nodeset_index = [];
        $blade_opcode = '';

        if (6118 == $model_type) {
            // Hardcore map, for 6118
            $blade_multiple_index = [
                    'A1' => 0,
                    'A2' => 1,
                    'A3' => 2,
                    'A4' => 3,
                    'A5' => 4,
                    'A6' => 5,
                    'A7' => 6,
                    'A8' => 7,
                    'A9' => 8,
                    'A10' => 9,
                    'A11' => 10,
                    'A12' => 11,
                    'A13' => 12,
                    'A14' => 13,
                    'B1' => 14,
                    'B2' => 15,
                    'B3' => 16,
                    'B4' => 17,
                    'B5' => 18,
                    'B6' => 19,
                    'B7' => 20,
                    'B8' => 21,
                    'B9' => 22,
                    'B10' => 23,
                    'B11' => 24,
                    'B12' => 25,
                    'B13' => 26,
                    'B14' => 27,
            ];

            // init
            for ($i = 0; $i < 28; $i++) {
                $blade_bladeset_index[$i] = '0';
                $blade_nodeset_index[$i] = '0000';
            }

            // blade input format
            // * Single: A1
            if (preg_match('/^[A-B]([1-9]|1[0-4])$/', $blade)) {
                $blade_node_index = $blade_multiple_index["$blade"];
                $blade_bladeset_index["$blade_node_index"] = '1';
                $blade_nodeset_index["$blade_node_index"] = '1000';
            // * Multiple consecutive: A1-B3
            } elseif (preg_match('/^[A-B]([0-9]|1[0-4])-[A-B]([0-9]|1[0-4])$/', $blade)) {
                $blade_nodes = preg_split('/-/', $blade);
                $blade_node_index_start = $blade_multiple_index["$blade_nodes[0]"];
                $blade_node_index_end = $blade_multiple_index["$blade_nodes[1]"];
                for ($j = $blade_node_index_start; $j <= $blade_node_index_end; $j++) {
                    $blade_bladeset_index["$j"] = '1';
                    $blade_nodeset_index["$j"] = '1000';
                }
            // * Multiple non-consecutive: A1,B5
            // * Multiple non-consecutive & consecutive: A1-A5,B7
            } elseif (preg_match('/,/', $blade)) {
                $tmps = preg_split('/,/', $blade);

                foreach ($tmps as $tmp) {
                    if (preg_match('/^[A-B]([1-9]|1[0-4])$/', $tmp)) {
                        $blade_node_index = $blade_multiple_index["$tmp"];
                        $blade_bladeset_index["$blade_node_index"] = '1';
                        $blade_nodeset_index["$blade_node_index"] = '1000';
                    } elseif (preg_match('/^[A-B]([0-9]|1[0-4])-[A-B]([0-9]|1[0-4])$/', $tmp)) {
                        $blade_nodes = preg_split('/-/', $tmp);
                        $blade_node_index_start = $blade_multiple_index["$blade_nodes[0]"];
                        $blade_node_index_end = $blade_multiple_index["$blade_nodes[1]"];
                        for ($j = $blade_node_index_start; $j <= $blade_node_index_end; $j++) {
                            $blade_bladeset_index["$j"] = '1';
                            $blade_nodeset_index["$j"] = '1000';
                        }
                    } else {
                        return "Error: Input Format Error, should (A1|A1-A2|A1,A2|A1-A2,A3)\n";
                    }
                }
            }
        } elseif (6219 == $model_type) {
            // init
            for ($i = 0; $i < 14; $i++) {
                $blade_bladeset_index[$i] = '0';
                $blade_nodeset_index[$i] = '0000';
            }

            // blade input format
            // * Single: A1N1
            if (preg_match('/^[A]([1-9]|1[0-4])[N][1-2]$/', $blade)) {
                $blade_node_index_tmp = explode("N", $blade);
                $blade_node_index_tmp[0] = preg_replace('/A/', '', $blade_node_index_tmp[0]);
                $blade_bladeset_index[$blade_node_index_tmp[0]-1] = '1';
                if (1 == $blade_node_index_tmp[1]) {
                    $blade_nodeset_index[$blade_node_index_tmp[0]-1] = '1000';
                } elseif (2 == $blade_node_index_tmp[1]) {
                    $blade_nodeset_index[$blade_node_index_tmp[0]-1] = '0100';
                }
            // * Multiple consecutive: A1N1-A3N2
            } elseif (preg_match('/^[A]([0-9]|1[0-4])[N][1-2]-[A]([0-9]|1[0-4])[N][1-2]$/', $blade)) {
                $blade_nodes = preg_split('/-/', $blade);
                $blade_nodes_index_start_tmp = explode("N", $blade_nodes[0]);
                $blade_nodes_index_start_tmp[0] = preg_replace('/A/', '', $blade_nodes_index_start_tmp[0]);
                $blade_nodes_index_end_tmp = explode("N", $blade_nodes[1]);
                $blade_nodes_index_end_tmp[0] = preg_replace('/A/', '', $blade_nodes_index_end_tmp[0]);

                for ($j = $blade_nodes_index_start_tmp[0] - 1; $j < $blade_nodes_index_end_tmp[0]; $j++) {
                    $blade_bladeset_index[$j] = '1';
                    $blade_nodeset_index[$j] = '1100';
                }

                if (2 == $blade_nodes_index_start_tmp[1]) {
                    $blade_nodeset_index[$blade_nodes_index_start_tmp[0]-1] = '0100';
                }
                if (1 == $blade_nodes_index_end_tmp[1]) {
                    $blade_nodeset_index[$blade_nodes_index_end_tmp[0]-1] = '1000';
                }
            // * Multiple non-consecutive: A1N1,A5N2
            // * Multiple non-consecutive & consecutive: A1N2-A5N2,A9N2
            } elseif (preg_match('/,/', $blade)) {
                $tmps = preg_split('/,/', $blade);

                foreach ($tmps as $tmp) {
                    if (preg_match('/^[A]([1-9]|1[0-4])[N][1-2]$/', $tmp)) {
                        $blade_node_index_tmp = explode("N", $tmp);
                        $blade_node_index_tmp[0] = preg_replace('/A/', '', $blade_node_index_tmp[0]);
                        $blade_bladeset_index[$blade_node_index_tmp[0]-1] = '1';
                        if (1 == $blade_node_index_tmp[1]) {
                            if ('0000' == $blade_nodeset_index[$blade_node_index_tmp[0]-1]) {
                                $blade_nodeset_index[$blade_node_index_tmp[0]-1] = '1000';
                            } elseif ('0100' == $blade_nodeset_index[$blade_node_index_tmp[0]-1]) {
                                $blade_nodeset_index[$blade_node_index_tmp[0]-1] = '1100';
                            }
                        } elseif (2 == $blade_node_index_tmp[1]) {
                            if ('0000' == $blade_nodeset_index[$blade_node_index_tmp[0]-1]) {
                                $blade_nodeset_index[$blade_node_index_tmp[0]-1] = '0100';
                            } elseif ('1000' == $blade_nodeset_index[$blade_node_index_tmp[0]-1]) {
                                $blade_nodeset_index[$blade_node_index_tmp[0]-1] = '1100';
                            }
                        }
                    } elseif (preg_match('/^[A]([0-9]|1[0-4])[N][1-2]-[A]([0-9]|1[0-4])[N][1-2]$/', $tmp)) {
                        $blade_nodes = preg_split('/-/', $tmp);

                        $blade_nodes_index_start_tmp = explode("N", $blade_nodes[0]);
                        $blade_nodes_index_start_tmp[0] = preg_replace('/A/', '', $blade_nodes_index_start_tmp[0]);
                        $blade_nodes_index_end_tmp = explode("N", $blade_nodes[1]);
                        $blade_nodes_index_end_tmp[0] = preg_replace('/A/', '', $blade_nodes_index_end_tmp[0]);

                        for ($j = $blade_nodes_index_start_tmp[0] - 1; $j < $blade_nodes_index_end_tmp[0]; $j++) {
                            $blade_bladeset_index[$j] = '1';
                            $blade_nodeset_index[$j] = '1100';
                        }

                        if (2 == $blade_nodes_index_start_tmp[1]) {
                            $blade_nodeset_index[$blade_nodes_index_start_tmp[0]-1] = '0100';
                        }
                        if (1 == $blade_nodes_index_end_tmp[1]) {
                            $blade_nodeset_index[$blade_nodes_index_end_tmp[0]-1] = '1000';
                        }
                    } else {
                        return "Error: Input Format Error, should (A1N1|A1N1-A5N2|A1N1,A5N2|A1N2-A5N2,A9N2)\n";
                    }
                }
            }
        }

        // Single & Multiple commands
        // Use /cgi/blade_status.cgi
        //
        if (preg_match('/^poweron$|^poweroff$|^powercycle$|^powerreset$|^gracefulshutdown$|^accycle$|^pwfailpolicy$|^aclostpolicy$|^powercapping$|^uidon$|^uidoff$/', $command)) {
            $result = '';

            $ch = curl_init('http://' . $ipmi_host . '/cgi/blade_status.cgi');
            curl_setopt($ch, CURLOPT_COOKIEJAR, COOKIE_FILE);
            curl_setopt($ch, CURLOPT_COOKIEFILE, COOKIE_FILE);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);

            $bladeset = implode('', $blade_bladeset_index);
            $nodeset = implode('', $blade_nodeset_index);

            switch ($command) {
                case 'poweron':
                    $blade_opcode = 1;
                    $result = 'Power On';
                    break;
                case 'poweroff':
                    $blade_opcode = 0;
                    $result = 'Power Off';
                    break;
                case 'powercycle':
                    $blade_opcode = 2;
                    $result = 'Power Cycle';
                    break;
                case 'powerreset':
                    $blade_opcode = 3;
                    $result = 'Power Reset';
                    break;
                case 'gracefulshutdown':
                    $blade_opcode = 5;
                    $result = 'Gracefully Shutdown';
                    break;
                case 'accycle':
                    $blade_opcode = 24;
                    $result = 'AC Cycle';
                    break;
                case 'pwfailpolicy':
                    $blade_opcode = 10;
                    switch ($subcommand) {
                        case 'poweroff':
                            $nodeset = preg_replace('/1/', '1', $nodeset);
                            break;
                        case 'throttle':
                            $nodeset = preg_replace('/1/', '2', $nodeset);
                            break;
                        case 'performance':
                            $nodeset = preg_replace('/1/', '3', $nodeset);
                            break;
                        default:
                            return "Error: Need Subcommand (poweroff|throttle|performance)\n";
                            break;
                    }
                    $result = 'Set PowerFail Policy = ' . $subcommand;
                    break;
                case 'powercapping':
                    $blade_opcode = 36;
                    switch ($subcommand) {
                        case 'readonly':
                            $nodeset = preg_replace('/1/', '0', $nodeset);
                            break;
                        case 'disabled':
                            $nodeset = preg_replace('/1/', '1', $nodeset);
                            break;
                        case '90percent':
                            $nodeset = preg_replace('/1/', '9', $nodeset);
                            break;
                        case '80percent':
                            $nodeset = preg_replace('/1/', '8', $nodeset);
                            break;
                        case '70percent':
                            $nodeset = preg_replace('/1/', '7', $nodeset);
                            break;
                        case '60percent':
                            $nodeset = preg_replace('/1/', '6', $nodeset);
                            break;
                        case '50percent':
                            $nodeset = preg_replace('/1/', '5', $nodeset);
                            break;
                        default:
                            return "Error: (6219 Only) Need Subcommand (readonly|disabled|90percent|80percent|70percent|60percent|50percent)\n";
                            break;
                    }
                    $result = 'Set Power Capping Policy = ' . $subcommand;
                    break;
                case 'aclostpolicy':
                    $blade_opcode = 9;
                    switch ($subcommand) {
                        case 'poweroff':
                            $nodeset = preg_replace('/1/', '1', $nodeset);
                            break;
                        case 'poweron':
                            $nodeset = preg_replace('/1/', '2', $nodeset);
                            break;
                        case 'laststate':
                            $nodeset = preg_replace('/1/', '3', $nodeset);
                            break;
                        default:
                            return "Error: Need Subcommand (poweroff|poweron|laststate)\n";
                            break;
                    }
                    $result = 'Set ACLost Policy = ' . $subcommand;
                    break;
                case 'uidon':
                    $blade_opcode = 7;
                    $result = 'Set UID On';
                    break;
                case 'uidoff':
                    $blade_opcode = 8;
                    $result = 'Set UID Off';
                    break;
            }

            curl_setopt($ch, CURLOPT_POSTFIELDS, 'RequestAction=' . $blade_opcode . '&BladeSet=' . $bladeset . '&NodeSet=' . $nodeset . '&_=');
            if (false === curl_exec($ch)) {
                $result = "Error: " . curl_error($ch);
            } else {
                $result = $result . " on Blade Nodes $blade, at CMM: $ipmi_host, successfully.\n";
            }
        } elseif (preg_match('/^bmcreset$|^bmcdefault$/', $command)) {
            // Single only command
            // Use /cgi/ipmi.cgi?BLADE_BTN_ACTION.XML=
            // Need to use multiRequest()
            $data = [];
            $count = 0;
            $result = '';

            if ('bmcreset' == $command) {
                $blade_opcode = 16;
                $result = 'BMC Reset';
            } elseif ('bmcdefault' == $command) {
                $blade_opcode = 17;
                $result = 'BMC Reset to Default';
            }

            if (6118 == $model_type) {
                for ($k = 0; $k < 28; $k++) {
                    if ($blade_bladeset_index["$k"]) {
                        $blade_node_index = sprintf("%x01", $k);
                        if ('001' == $blade_node_index) {
                            $blade_node_index = '1';
                        }
                        $data[$count]['url'] = 'http://' . $ipmi_host . '/cgi/ipmi.cgi';
                        $data[$count]['post']['BLADE_BTN_ACTION.XML'] = '(' . $blade_node_index . ',' . $blade_opcode . ')';
                    }
                    $count++;
                }
            } elseif (6219 == $model_type) {
                for ($k = 0; $k < 14; $k++) {
                    if ('1000' == $blade_nodeset_index[$k]) {
                        $blade_node_index = sprintf("%x01", $k);
                    } elseif ('0100' == $blade_nodeset_index[$k]) {
                        $blade_node_index = sprintf("%x02", $k);
                    } elseif ('1100' == $blade_nodeset_index[$k]) {
                        $blade_node_index = sprintf("%x01", $k);
                        $blade_node_index2 = sprintf("%x02", $k);
                    }

                    if ('001' == $blade_node_index) {
                        $blade_node_index = '1';
                    } elseif ('002' == $blade_node_index) {
                        $blade_node_index = '2';
                    }

                    if (!empty($blade_node_index)) {
                        $data[$count]['url'] = 'http://' . $ipmi_host . '/cgi/ipmi.cgi';
                        $data[$count]['post']['BLADE_BTN_ACTION.XML'] = '(' . $blade_node_index . ',' . $blade_opcode . ')';
                        $count++;
                    }

                    if ($blade_node_index2) {
                        $data[$count]['url'] = 'http://' . $ipmi_host . '/cgi/ipmi.cgi';
                        $data[$count]['post']['BLADE_BTN_ACTION.XML'] = '(' . $blade_node_index2 . ',' . $blade_opcode . ')';
                        $count++;
                    }

                    // reset
                    $blade_node_index = '';
                    $blade_node_index2 = '';
                }
            }

            $contents = multiRequest($data);
            foreach ($contents as $content_key => $content) {
                if (!preg_match('/200\ OK/', $content)) {
                    $result = 'Error: Node ' . array_search($content_key, $blade_multiple_index) . ', ' . $result . ' Failed';
                }
            }
            $result = $result . " on Blade Nodes $blade, at CMM: $ipmi_host, successfully.\n";
        }
        return $result;
    } else {
        $result = Usage('blade');
        return $result;
    }
}

// Usage
function Usage($commandType) {
    global $argv;
    $result = '';

    switch ($commandType) {
        case 'general':
            $result = "General Usage: " . $argv[0] .
                    "   \t-H IPMI_HOST
                        \t\t\t-U IPMI_USER
                        \t\t\t-P IPMI_PASSWORD
                        \t\t\t-T (blade|psu|switch|cmm)
                        \t\t\t-C COMMAND
                        \t\t\t-N NODE
                        \t\t\t-S SUBCOMMAND\n\n";
            break;
        case 'psu':
            $result = "  * For MBI-6118-D Usage: \n\t" . $argv[0] .
                        " \t-H IPMI_HOST
                        \t\t-U IPMI_USER
                        \t\t-P IPMI_PASSWORD
                        \t\t-T psu
                        \t\t-C (status|detail|poweron|poweroff)
                        \t\t-N (A[1-4]|B[1-4])\n\n";
            $result = $result . "  * For MBI-6219-G Usage: \n\t" . $argv[0] .
                        " \t-H IPMI_HOST
                        \t\t-U IPMI_USER
                        \t\t-P IPMI_PASSWORD
                        \t\t-T psu -C (status|detail)\n";
            break;
        case 'switch':
            $result = "Usage: \n\t" . $argv[0] .
                    " \t-H IPMI_HOST
                    \t\t\t-U IPMI_USER
                    \t\t\t-P IPMI_PASSWORD
                    \t\t\t-T switch
                    \t\t\t-C (status|detail|poweron|poweroff|hwreset|reboot|resetdefault|uidon|uidoff)
                    \t\t\t-N (A1|A2)\n";
            break;
        case 'cmm':
            $result = "Usage: \n\t" . $argv[0] .
                    " \t-H IPMI_HOST
                    \t\t\t-U IPMI_USER
                    \t\t\t-P IPMI_PASSWORD
                    \t\t\t-T cmm
                    \t\t\t-C (status|detail)\n";
            break;
        case 'blade':
            $result = "  * For MBI-6118-D Usage: \n\t" . $argv[0] .
                    " \t-H IPMI_HOST
                      \t\t\t-U IPMI_USER
                      \t\t\t-P IPMI_PASSWORD
                      \t\t\t-T blade
                      \t\t\t-C (status|detail|poweron|poweroff|powercycle|powerreset|gracefulshutdown|accycle|pwfailpolicy|aclostpolicy|bmcreset|bmcdefault|uidon|uidoff)
                      \t\t\t    (For: -C pwfailpolicy) -S (poweroff|throttle|performance)
                      \t\t\t    (For: -C aclostpolicy) -S (poweroff|poweron|laststate)
                      \t\t\t-N (A1|A1-B4|A1-A3,B5,B7-B8)\n\n";
            $result = $result . "  * For MBI-6219-G Usage: \n\t" . $argv[0] .
                     "\t-H IPMI_HOST
                      \t\t\t-U IPMI_USER
                      \t\t\t-P IPMI_PASSWORD
                      \t\t\t-T blade
                      \t\t\t-C (status|detail|poweron|poweroff|powercycle|powerreset|gracefulshutdown|accycle|pwfailpolicy|aclostpolicy|powercapping|bmcreset|bmcdefault|uidon|uidoff)
                      \t\t\t    (For: -C pwfailpolicy) -S (poweroff|throttle|performance)
                      \t\t\t    (For: -C aclostpolicy) -S (poweroff|poweron|laststate)
                      \t\t\t    (For: -C powercapping) -S (readonly|disabled|90percent|80percent|70percent|60percent|50percent)
                      \t\t\t-N (A1N2|A1N2-A4N1|A1N2-A4N1,A7N2)\n";
            break;
        default:
            $result = Usage('general');
            break;
    }

    print $result;
    return;
}

function multiRequest($data, $options = array()) {
    // array of curl handles
    $curly = array();
    // data to be returned
    $result = array();

    // multi handle
    $mh = curl_multi_init();

    // loop through $data and create curl handles
    // then add them to the multi-handle
    foreach ($data as $id => $d) {
        $curly[$id] = curl_init();

        $url = (is_array($d) && !empty($d['url'])) ? $d['url'] : '';
        curl_setopt($curly[$id], CURLOPT_URL, $url);
        curl_setopt($curly[$id], CURLOPT_HEADER, true);
        curl_setopt($curly[$id], CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curly[$id], CURLOPT_COOKIEJAR, COOKIE_FILE);
        curl_setopt($curly[$id], CURLOPT_COOKIEFILE, COOKIE_FILE);

        // post?
        if (is_array($d)) {
            if (!empty($d['post'])) {
                curl_setopt($curly[$id], CURLOPT_POST, true);
                $post_value = '';
                foreach ($d['post'] as $key => $value) {
                    $post_value = $post_value . $key . "=" . $value . "&";
                }
                curl_setopt($curly[$id], CURLOPT_POSTFIELDS, $post_value);
            }
        }

        // extra options?
        if (!empty($options)) {
            curl_setopt_array($curly[$id], $options);
        }

        curl_multi_add_handle($mh, $curly[$id]);
    }

    // execute the handles
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while($running > 0);

    // get content and remove handles
    foreach ($data as $id => $d) {
        if (!($curly_tmp = @$curly[$id])) {
            $result[$id] = '';
            continue;
        }

        $errno = curl_errno($curly_tmp);
        $http_code = curl_getinfo($curly_tmp, CURLINFO_HTTP_CODE);

        $result[$id] = (($http_code != 200) && ($errno != 0)) ? '' : curl_multi_getcontent($curly_tmp);

        curl_close($curly_tmp);
        curl_multi_remove_handle($mh, $curly_tmp);
    }

    // all done
    curl_multi_close($mh);

    return $result;
}

function ping($host, $port = 80, $timeout = 3)
{
    $fsock = fsockopen($host, $port, $errno, $errstr, $timeout);
    $ping_status = $fsock ? TRUE : FALSE;

    return $ping_status;
}


?>
