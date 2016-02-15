<?php

class HTMLEditTools extends HTMLFormField {
	public function getInputHTML( $value ) {
		return '';
	}

	public function getTableRow( $value ) {
		return
			'<tr><td></td><td class="mw-input">' .
			'<div class="mw-editTools">' .
			$this->formatParsed() .
			"</div></td></tr>\n";
	}

	/**
	 * @param string $value
	 * @return string
	 * @since 1.20
	 */
	public function getDiv( $value ) {
		return '<div class="mw-editTools">' . $this->formatParsed() . '</div>';
	}

	/**
	 * @param string $value
	 * @return string
	 * @since 1.20
	 */
	public function getRaw( $value ) {
		return $this->getDiv( $value );
	}

	protected function formatParsed() {
		$html = $this->formatMsg()->parseAsBlock();
		$out = MessageCache::singleton()->getParser()->getOutput();
		$this->mParent->getOutput()->addModules( $out->getModules() );
		$this->mParent->getOutput()->addModuleStyles( $out->getModuleStyles() );
		$this->mParent->getOutput()->addModuleScripts( $out->getModuleScripts() );
		return $html;
	}

	protected function formatMsg() {
		if ( empty( $this->mParams['message'] ) ) {
			$msg = $this->msg( 'edittools' );
		} else {
			$msg = $this->msg( $this->mParams['message'] );
			if ( $msg->isDisabled() ) {
				$msg = $this->msg( 'edittools' );
			}
		}
		$msg->inContentLanguage();

		return $msg;
	}
}
