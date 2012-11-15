<?php
/**
 * Language names.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Language
 */

/**
 * These determine things like interwikis, language selectors, and so on.
 * Safe to change without running scripts on the respective sites.
 *
 * \xE2\x80\x8E is the left-to-right marker and
 * \xE2\x80\x8F is the right-to-left marker.
 * They are required for ensuring the correct display of brackets in
 * mixed rtl/ltr environment.
 *
 * Some writing systems require some line-height fixes. This includes
 * most Indic scripts, like Devanagari.
 * If you are adding support for such a language, add it also to
 * the relevant section in shared.css.
 *
 * @ingroup Language
 */
/* private */ $coreLanguageNames = array(
	'de' => 'Deutsch',		# German ("Du")
	'en' => 'English',		# English
	'fr' => 'français',	# French
	'it' => 'italiano',		# Italian
	'es' => 'español',	# Spanish
	'ru' => 'русский',	# Russian
                 'zh-hans' => "中文（简体）\xE2\x80\x8E",	# Mandarin Chinese (Simplified Chinese script) (cmn-hans)
);
