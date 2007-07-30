<?php
//
// Created on: <09-May-2003 10:44:02 bf>
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: eZ publish
// SOFTWARE RELEASE: 3.10.x
// COPYRIGHT NOTICE: Copyright (C) 1999-2006 eZ systems AS
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
//
//
// ## END COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
//

$http = eZHTTPTool::instance();
$module = $Params["Module"];
$parameters =& $Params["Parameters"];

$overrideKeys = array( 'nodeID' => $Params['NodeID'],
                       'classID' => $Params['ClassID'] );

include_once( "kernel/common/template.php" );
include_once( "kernel/common/eztemplatedesignresource.php" );
include_once( 'lib/ezutils/classes/ezhttptool.php' );
include_once( "kernel/classes/ezcontentclass.php" );

$ini = eZINI::instance();
$tpl = templateInit();

// Todo: read from siteaccess settings
$siteAccess = $Params['SiteAccess'];
if( $siteAccess )
    $http->setSessionVariable( 'eZTemplateAdminCurrentSiteAccess', $siteAccess );
else
    $siteAccess = $http->sessionVariable( 'eZTemplateAdminCurrentSiteAccess' );

$siteBase = $siteAccess;

$siteINI = eZINI::instance( 'site.ini', 'settings', null, null, true );
$siteINI->prependOverrideDir( "siteaccess/$siteAccess", false, 'siteaccess' );
$siteINI->loadCache();
$siteDesign = $siteINI->variable( "DesignSettings", "SiteDesign" );

$template = "";
foreach ( $parameters as $param )
{
    $template .= "/$param";
}


$templateType = 'default';
if ( strpos( $template, "node/view" ) )
{
    $templateType = 'node_view';
}
else if ( strpos( $template, "content/view" ) )
{
    $templateType = 'object_view';
}
else if ( strpos( $template, "content/edit" ) )
{
    $templateType = 'object_view';
}
else if ( strpos( $template, "pagelayout.tpl" ) )
{
    $templateType = 'pagelayout';
}

$error = false;
$templateName = false;

if ( $module->isCurrentAction( 'CreateOverride' ) )
{
    $templateName = trim( $http->postVariable( 'TemplateName' ) );

    if ( preg_match( "#^[0-9a-z_]+$#", $templateName ) )
    {
        $templateName = trim( $http->postVariable( 'TemplateName' ) );
        $fileName = "design/$siteDesign/override/templates/" . $templateName . ".tpl";
        $filePath = "design/$siteDesign/override/templates";

        $templateCode = "";
        switch ( $templateType )
        {
            case "node_view":
            {
                $templateCode =& generateNodeViewTemplate( $http, $template, $fileName );
            }break;

            case "object_view":
            {
                $templateCode =& generateObjectViewTemplate( $http, $template, $fileName );
            }break;

            case "pagelayout":
            {
                $templateCode =& generatePagelayoutTemplate( $http, $template, $fileName );
            }break;

            default:
            {
                $templateCode =& generateDefaultTemplate( $http, $template, $fileName );
            }break;
        }

        if ( !file_exists( $filePath ) )
        {
            $dirPermission = $ini->variable( 'FileSettings', 'StorageDirPermissions' );

            eZDir::mkdir( $filePath, eZDir::directoryPermission(), true );
        }


        $fp = fopen( $fileName, "w+" );
        if ( $fp )
        {
            $filePermission = $ini->variable( 'FileSettings', 'StorageFilePermissions' );
            $oldumask = umask( 0 );
            fwrite( $fp, $templateCode );
            fclose( $fp );
            chmod( $fileName, octdec( $filePermission ) );
            umask( $oldumask );

            // Store override.ini.append file
            $overrideINI = eZINI::instance( 'override.ini', 'settings', null, null, true );
            $overrideINI->prependOverrideDir( "siteaccess/$siteAccess", false, 'siteaccess' );
            $overrideINI->loadCache();

            $templateFile = preg_replace( "#^/(.*)$#", "\\1", $template );

            $overrideINI->setVariable( $templateName, 'Source', $templateFile );
            $overrideINI->setVariable( $templateName, 'MatchFile', $templateName . ".tpl" );
            $overrideINI->setVariable( $templateName, 'Subdir', "templates" );

            if ( $http->hasPostVariable( 'Match' ) )
            {
                $matchArray = $http->postVariable( 'Match' );

                foreach ( array_keys( $matchArray ) as $matchKey )
                {
                    if ( $matchArray[$matchKey] == -1 or trim( $matchArray[$matchKey] ) == "" )
                        unset( $matchArray[$matchKey] );
                }
                $overrideINI->setVariable( $templateName, 'Match', $matchArray );
            }

            $oldumask = umask( 0 );
            $overrideINI->save( "siteaccess/$siteAccess/override.ini.append" );
            chmod( "settings/siteaccess/$siteAccess/override.ini.append.php", octdec( $filePermission ) );
            umask( $oldumask );

            // Expire content view cache
            include_once( 'kernel/classes/ezcontentcachemanager.php' );
            eZContentCacheManager::clearAllContentCache();

            // Clear override cache
            $cachedDir = eZSys::cacheDirectory();
            $cachedDir .= "/override/";
            eZDir::recursiveDelete( $cachedDir );
        }
        else
        {
            $error = "permission_denied";
            eZDebug::writeError( "Could not create override template, check permissions on $fileName", "Template override" );
        }
    }
    else
    {
        $error = "invalid_name";
    }

    if ( $error == false )
    {
        $module->redirectTo( '/visual/templateview'. $template );
        return EZ_MODULE_HOOK_STATUS_CANCEL_RUN;
    }
}
else if( $module->isCurrentAction( 'CancelOverride' ) )
{
   $module->redirectTo( '/visual/templateview'. $template );
}


function &generateNodeViewTemplate( &$http, $template, $fileName )
{
    $matchArray = $http->postVariable( 'Match' );

    $templateCode = "";
    $classIdentifier = $matchArray['class_identifier'];

    $class = eZContentClass::fetchByIdentifier( $classIdentifier );

    // Check what kind of contents we should create in the template
    switch ( $http->postVariable( 'TemplateContent' ) )
    {
        case 'DefaultCopy' :
        {
            $siteAccess = $http->sessionVariable( 'eZTemplateAdminCurrentSiteAccess' );
            $overrideArray = eZTemplateDesignResource::overrideArray( $siteAccess );
            $fileName = $overrideArray[$template]['base_dir'] . $overrideArray[$template]['template'];
            $fp = fopen( $fileName, 'rb' );
            if ( $fp )
            {
                $codeFromFile = fread( $fp, filesize( $fileName ) );

                // Remove the "{* DO NOT EDIT... *}" first line (if exists).
                $templateCode = preg_replace('@^{\*\s*DO\sNOT\sEDIT.*?\*}\n(.*)@s', '$1', $codeFromFile);
            }
            else
            {
                eZDebug::writeError( "Could not open file $fileName, check read permissions" );
            }
            fclose( $fp );
        }break;

        case 'ContainerTemplate' :
        {
            $templateCode = "<h1>{\$node.name}</h1>\n\n";

            // Append attribute view
            if ( strtolower( get_class( $class ) ) == "ezcontentclass" )
            {
                $attributes = $class->fetchAttributes();
                foreach ( $attributes as $attribute )
                {
                    $identifier = $attribute->attribute( 'identifier' );
                    $name = $attribute->attribute( 'name' );
                    $templateCode .= "<h2>$name</h2>\n";
                    $templateCode .= "{attribute_view_gui attribute=\$node.object.data_map.$identifier}\n\n";
                }
            }

            $templateCode .= "" .
                 "{let page_limit=20\n" .
                 "    children=fetch('content','list',hash(parent_node_id,\$node.node_id,sort_by,\$node.sort_array,limit,\$page_limit,offset,\$view_parameters.offset))" .
                 "    list_count=fetch('content','list_count',hash(parent_node_id,\$node.node_id))}\n" .
                 "\n" .
                 "{section name=Child loop=\$children sequence=array(bglight,bgdark)}\n" .
                 "{node_view_gui view=line content_node=\$Child:item}\n" .
                 "{/section}\n" .

                 "{include name=navigator\n" .
                 "    uri='design:navigator/google.tpl'\n" .
                 "    page_uri=concat('/content/view','/full/',\$node.node_id)\n" .
                 "    item_count=\$list_count\n" .
                 "    view_parameters=\$view_parameters\n" .
                 "    item_limit=\$page_limit}\n";
            "{/let}\n";
        }break;

        case 'ViewTemplate' :
        {
            $templateCode = "<h1>{\$node.name}</h1>\n\n";

            // Append attribute view
            if ( strtolower( get_class( $class ) ) == "ezcontentclass" )
            {
                $attributes = $class->fetchAttributes();
                foreach ( $attributes as $attribute )
                {
                    $identifier = $attribute->attribute( 'identifier' );
                    $name = $attribute->attribute( 'name' );
                    $templateCode .= "<h2>$name</h2>\n";
                    $templateCode .= "{attribute_view_gui attribute=\$node.object.data_map.$identifier}\n\n";
                }
            }

        }break;

        default:
        case 'EmptyFile' :
        {
        }break;
    }

    return $templateCode;
}


function &generateObjectViewTemplate( &$http, $template, $fileName )
{
    $matchArray = $http->postVariable( 'Match' );

    $templateCode = "";
    $classID = $matchArray['class'];

    $class = eZContentClass::fetch( $classID );

    // Check what kind of contents we should create in the template
    switch ( $http->postVariable( 'TemplateContent' ) )
    {
        case 'DefaultCopy' :
        {
            $siteAccess = $http->sessionVariable( 'eZTemplateAdminCurrentSiteAccess' );
            $overrideArray = eZTemplateDesignResource::overrideArray( $siteAccess );
            $fileName = $overrideArray[$template]['base_dir'] . $overrideArray[$template]['template'];
            $fp = fopen( $fileName, 'rb' );
            if ( $fp )
            {
                $codeFromFile = fread( $fp, filesize( $fileName ) );

                // Remove the "{* DO NOT EDIT... *}" first line (if exists).
                $templateCode = preg_replace('@^{\*\s*DO\sNOT\sEDIT.*?\*}\n(.*)@s', '$1', $codeFromFile);
            }
            else
            {
                eZDebug::writeError( "Could not open file $fileName, check read permissions" );
            }
            fclose( $fp );
        }break;

        case 'ViewTemplate' :
        {
            $templateCode = "<h1>{\$object.name}</h1>\n\n";

            // Append attribute view
            if ( strtolower( get_class( $class ) ) == "ezcontentclass" )
            {
                $attributes = $class->fetchAttributes();
                foreach ( $attributes as $attribute )
                {
                    $identifier = $attribute->attribute( 'identifier' );
                    $name = $attribute->attribute( 'name' );
                    $templateCode .= "<h2>$name</h2>\n";
                    $templateCode .= "{attribute_view_gui attribute=\$object.data_map.$identifier}\n\n";
                }
            }

        }break;

        default:
        case 'EmptyFile' :
        {
        }break;
    }
    return $templateCode;
}

function &generatePagelayoutTemplate( &$http, $template, $fileName )
{
    $templateCode = "";
    // Check what kind of contents we should create in the template
    switch ( $http->postVariable( 'TemplateContent' ) )
    {
        case 'DefaultCopy' :
        {
            $siteAccess = $http->sessionVariable( 'eZTemplateAdminCurrentSiteAccess' );
            $overrideArray = eZTemplateDesignResource::overrideArray( $siteAccess );
            $fileName = $overrideArray[$template]['base_dir'] . $overrideArray[$template]['template'];
            $fp = fopen( $fileName, 'rb' );
            if ( $fp )
            {
                $codeFromFile = fread( $fp, filesize( $fileName ) );

                // Remove the "{* DO NOT EDIT... *}" first line (if exists).
                $templateCode = preg_replace('@^{\*\s*DO\sNOT\sEDIT.*?\*}\n(.*)@s', '$1', $codeFromFile);
            }
            else
            {
                eZDebug::writeError( "Could not open file $fileName, check read permissions" );
            }
            fclose( $fp );
        }break;

        default:
        case 'EmptyFile' :
        {
            $templateCode = '{*?template charset=latin1?*}' .
                 '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" ' .
                 '"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\n" .
                 '<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="no" lang="no">' .
                 '<head>' . "\n" .
                 '    <link rel="stylesheet" type="text/css" href={"stylesheets/core.css"|ezdesign} />' . "\n" .
                 '    <link rel="stylesheet" type="text/css" href={"stylesheets/debug.css"|ezdesign} />' . "\n" .
                 '    {include uri="design:page_head.tpl"}' . "\n" .
                 '</head>' . "\n" .
                 '<body>' . "\n" .
                 '{$module_result.content}' . "\n" .
                 '<!--DEBUG_REPORT-->' . "\n" .
                 '</body>' . "\n" .
                 '</html>' . "\n";
        }break;
    }
    return $templateCode;
}

function &generateDefaultTemplate( &$http, $template, $fileName )
{
    $templateCode = "";
    // Check what kind of contents we should create in the template
    switch ( $http->postVariable( 'TemplateContent' ) )
    {
        case 'DefaultCopy' :
        {
            $siteAccess = $http->sessionVariable( 'eZTemplateAdminCurrentSiteAccess' );
            $overrideArray = eZTemplateDesignResource::overrideArray( $siteAccess );
            $fileName = $overrideArray[$template]['base_dir'] . $overrideArray[$template]['template'];
            $fp = fopen( $fileName, 'rb' );
            if ( $fp )
            {
                $codeFromFile = fread( $fp, filesize( $fileName ) );

                // Remove the "{* DO NOT EDIT... *}" first line (if exists).
                $templateCode = preg_replace('@^{\*\s*DO\sNOT\sEDIT.*?\*}\n(.*)@s', '$1', $codeFromFile);
            }
            else
            {
                eZDebug::writeError( "Could not open file $fileName, check read permissions" );
            }
            fclose( $fp );
        }break;

        default:
        case 'EmptyFile' :
        {
            $templateCode = '{*?template charset=latin1?*}' .
                 '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" ' .
                 '"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\n" .
                 '<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="no" lang="no">' .
                 '<head>' . "\n" .
                 '    <link rel="stylesheet" type="text/css" href={"stylesheets/core.css"|ezdesign} />' . "\n" .
                 '    <link rel="stylesheet" type="text/css" href={"stylesheets/debug.css"|ezdesign} />' . "\n" .
                 '    {include uri="design:page_head.tpl"}' . "\n" .
                 '</head>' . "\n" .
                 '<body>' . "\n" .
                 '{$module_result.content}' . "\n" .
                 '<!--DEBUG_REPORT-->' . "\n" .
                 '</body>' . "\n" .
                 '</html>' . "\n";
        }break;
    }
    return $templateCode;
}


$tpl->setVariable( 'error', $error );
$tpl->setVariable( 'template', $template );
$tpl->setVariable( 'template_type', $templateType );
$tpl->setVariable( 'template_name', $templateName );
$tpl->setVariable( 'site_base', $siteBase );
$tpl->setVariable( 'site_design', $siteDesign );
$tpl->setVariable( 'override_keys', $overrideKeys );

$Result = array();
$Result['content'] = $tpl->fetch( "design:visual/templatecreate.tpl" );
$Result['path'] = array( array( 'url' => "/visual/templatelist/",
                                'text' => ezi18n( 'kernel/design', 'Template list' ) ),
                         array( 'url' => "/visual/templateview". $template,
                                'text' => ezi18n( 'kernel/design', 'Template view' ) ),
                         array( 'url' => false,
                                'text' => ezi18n( 'kernel/design', 'Create new template' ) ) );
?>
