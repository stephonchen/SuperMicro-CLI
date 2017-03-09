# SuperMicro-CLI
SuperMicro CLI Tools using PHP and CURL

* MicroBlade CLI
  * Filename: smc_microblade_cli.php
  * Currently support for MBI-6118D and MBI-6219G only
  * Usage:


    ./smc_microblade_cli.php         -H IPMI_HOST \
                                     -U IPMI_USER \
                                     -P IPMI_PASSWORD \
                                     -T (blade|psu|switch|cmm) \
                                     -C COMMAND \
                                     -N NODE \
                                     -S SUBCOMMAND

  * Further commands will shown with


    -T (blade|psu|switch|cmm)
