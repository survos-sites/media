#!/usr/bin/env bash

bin/console survos:user:create super@survos.com tt --roles ROLE_SUPER_ADMIN
bin/console survos:user:create tac@survos.com tt  --roles ROLE_ADMIN
bin/console survos:user:create  aliciasouberbielle@hotmail.com ferr0
bin/console survos:user:create  pnewman@elmodo.mx elm0d0

bin/console survos:user:create  tacman@gmail.com tt --roles ROLE_ALLOWED_TO_SWITCH --roles ROLE_ADMIN
bin/console survos:user:create  researcher@museado.org researcher
bin/console survos:user:create  researcher@museado.com researcher
bin/console survos:user:create  visitante@museado.com visitante
bin/console survos:user:create  visitor@museado.com visitor
bin/console survos:user:create  gmezguillemurillo@gmail.com Peppa122016#
bin/console survos:user:create  ana@museolaesquina.org.mx e5quina
bin/console survos:user:create  ana@museado.com Cora2023
bin/console survos:user:create    maamf_zac@yahoo.com.mx Manuel01
bin/console survos:user:create    jesus.garcia@uaem.mx muaic
bin/console survos:user:create   angelica@museolaesquina.org.mx e5quina
bin/console survos:user:create  eduardo@museolaesquina.org.mx e5quina


#
#bin/console survos:user:create  maamf_zac@yahoo.com.mx felguerez
#bin/console survos:user:create  julieta@survos.com tt
