<?php

declare(strict_types=1);

namespace IvoPdf\Compat;

/**
 * Names (lower case) of the public methods of mPDF 8 (`Mpdf\Mpdf`). IvoPdf::__call() (see MpdfDraw) answers calls of the ones
 * IvoPDF does not implement with a warning and a neutral return value instead of a fatal "undefined method" Error.
 */
final class MpdfMethods
{
    public const NAMES = [
        '_advancefloatmargins', '_arc', '_beginpage', '_borderpadding', '_chardefined', '_computelineheight',
        '_createhtmlheaderfooter', '_dochecks', '_dounderline', '_ellipsearc', '_enddoc', '_endpage',
        '_fixtableborders', '_getborderwidths', '_getcharwidth', '_gethtmlheight', '_getlistmarkerwidth',
        '_getnormallineheight', '_getobjattr', '_getprevchar', '_getstylednumber', '_kern', '_movetoprevchar', '_out',
        '_packcellborder', '_postforcedpagebreak', '_preforcedpagebreak', '_printlistbullet', '_putextgstates',
        '_puthtmlheaders', '_resizebackgroundimage', '_reversetabledir', '_savecelltextbuffer', '_savetextbuffer',
        '_set_object_javascript', '_setautofooterheight', '_setautoheaderheight', '_setbidicodes', '_setborderline',
        '_setclippingpath', '_setdashborder', '_setinlineblockheights', '_setlineypos', '_setlistmarker',
        '_setpagesize', '_settextrendering', '_shareheaderfooterwidth', '_splittablegetwidth', '_table2cellborder',
        '_tablecolumnwidth', '_tablegetheight', '_tablegetmaxrowheight', '_tablegetwidth', '_tableheight',
        '_tablerect', '_tablewidth', '_tablewrite', '_transform', '_unpackcellborder', 'acceptpagebreak',
        'addbig5font', 'addcidfont', 'addcjkfont', 'addcolumn', 'addcustomproperty', 'addextgstate', 'addfont',
        'addfontdirectory', 'addgbfont', 'addlink', 'addpage', 'addpagebyarray', 'addsjisfont', 'addspotcolor',
        'addspotcolorsfromfile', 'adduhcfont', 'adjusthtml', 'aliasnbpagegroups', 'aliasnbpages',
        'all_entities_to_utf8', 'annotation', 'applygpospdf', 'arrow', 'autosizetext', 'beginlayer', 'bookmark',
        'border_details', 'cell', 'circle', 'circulartext', 'cleanup', 'clearfloats', 'close', 'columnadjustadd',
        'columnadjustpregreplace', 'convertangle', 'date_callback', 'deffooterbyname', 'defheaderbyname',
        'defhtmlfooterbyname', 'defhtmlheaderbyname', 'deletepages', 'disabletags', 'divln', 'docpagenum',
        'docpagenumtotal', 'docpagesettings', 'ellipse', 'endlayer', 'finishflowingblock', 'fixlineheight', 'footer',
        'fpdiimportpage', 'fpdiuseimportedpage', 'getbasicotldata', 'getcharwidth', 'getcharwidthcore',
        'getcharwidthnoncore', 'getfirstblockfill', 'getfloatdivinfo', 'getfontdescriptor', 'getfullpath',
        'getimportedexternalpagelinks', 'getimportedpages', 'getimportedpagesize', 'getjspacing', 'getstringwidth',
        'gettemplatesize', 'header', 'image', 'importpage', 'indexentry', 'indexentrysee', 'initialiseblock',
        'inlineobject', 'insertindex', 'is_utf8', 'issetborder', 'lesser_entity_decode', 'line', 'link', 'ln',
        'magic_reverse_dir', 'markscripttolang', 'movepages', 'multicell', 'newcolumn', 'newflowingblock', 'open',
        'output', 'outputbinarydata', 'outputfile', 'outputhttpdownload', 'outputhttpinline', 'overwrite', 'pageno',
        'paintdivbb', 'paintdivlnborder', 'paintimgborder', 'printbodybackgrounds', 'printbuffer', 'printcellbuffer',
        'printcolumnbuffer', 'printfloatbuffer', 'printkwtbuffer', 'printobjectbuffer', 'printpagebackgrounds',
        'printtablebackgrounds', 'printtablebuffer', 'purify_utf8', 'purify_utf8_text', 'read_short', 'readcharset',
        'readmetatags', 'rect', 'reset', 'resetmargins', 'resetspacing', 'resetstyles', 'restartdoctemplate',
        'restorefont', 'restoreinlineproperties', 'restrictunicodefonts', 'rotate', 'roundedrect', 'savefont',
        'saveinlineproperties', 'setadditionalxmprdf', 'setalpha', 'setanchor2bookmark', 'setassociatedfiles',
        'setauthor', 'setautopagebreak', 'setbackground', 'setbasepath', 'setborder', 'setcol', 'setcolor',
        'setcolumns', 'setcompression', 'setcreator', 'setcss', 'setdash', 'setdcolor', 'setdefaultbodycss',
        'setdefaultfont', 'setdefaultfontsize', 'setdirectionality', 'setdisplaymode', 'setdisplaypreferences',
        'setdoctemplate', 'setdrawcolor', 'setfcolor', 'setfillcolor', 'setfont', 'setfontsize', 'setfooter',
        'setfooterbyname', 'setheader', 'setheaderbyname', 'sethientitysubstitutions', 'sethtmlfooter',
        'sethtmlfooterbyname', 'sethtmlheader', 'sethtmlheaderbyname', 'setimportedpagelinks', 'setjs', 'setkeywords',
        'setleftmargin', 'setlinecap', 'setlineheight', 'setlinejoin', 'setlinewidth', 'setlink', 'setlogger',
        'setmargins', 'setmbencoding', 'setpagedmediacss', 'setpagetemplate', 'setprotection', 'setrightmargin',
        'setsourcefile', 'setsourcefilewithparserparams', 'setspacing', 'setstyle', 'setstyles', 'setstylesarray',
        'setsubject', 'setsubstitutions', 'settcolor', 'settextcolor', 'settextoutline', 'settitle', 'settopmargin',
        'setvisibility', 'setwatermarkimage', 'setwatermarktext', 'setx', 'setxy', 'sety', 'shaded_box',
        'shrinktable', 'starttransform', 'stoptransform', 'substitutechars', 'substitutecharsmb',
        'substitutecharsnoncore', 'substitutecharssip', 'substitutehientities', 'tablecheckminwidth',
        'tableheaderfooter', 'tabs2spaces_callback', 'text', 'thumbnail', 'toc', 'toc_entry', 'tocpagebreak',
        'tocpagebreakbyarray', 'tofloat', 'transformrotate', 'transformscale', 'transformskew', 'transformtranslate',
        'useimportedpage', 'usetemplate', 'utf8stringtoarray', 'utf8tosubset', 'watermark', 'watermarkimg', 'write',
        'writebarcode', 'writebarcode2', 'writecell', 'writefixedposhtml', 'writeflowingblock', 'writehtml',
        'writehtmlfooters', 'writehtmlheaders', 'writeimportedpagesandresolvedobjects', 'writepdftype', 'writetext',
    ];
}
