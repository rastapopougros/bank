<?php
/*
 * Paiement Bancaire
 * module de paiement bancaire multi prestataires
 * stockage des transactions
 *
 * Auteurs :
 * Cedric Morin, Nursit.com
 * (c) 2012-2015 - Distribue sous licence GNU/GPL
 *
 */
if (!defined('_ECRIRE_INC_VERSION')) return;

include_spip('presta/paybox/inc/paybox');
include_spip('inc/date');

/**
 * Verifier le statut d'une transaction lors du retour de l'internaute
 *
 * @param array $config
 * @param null|array $response
 * @return array
 */
function presta_paybox_call_response_dist($config, $response=null){

	include_spip('inc/bank');
	$mode = $config['presta'];

	if (!$response)
		// recuperer la reponse en post et la decoder
		$response = paybox_response();

	if (!$response) {
		return array(0,false);
	}

	if ($response['ETAT_PBX']==='PBX_RECONDUCTION_ABT'){
		// c'est un revouvellement initie par paybox : creer la transaction maintenant si besoin !
		if ($preparer_echeance = charger_fonction('preparer_echeance','abos',true)){
			// on reinjecte le bon id de transaction ici si fourni
			if ($id_transaction = $preparer_echeance("uid:".$response['abo'])){
				$response['id_transaction'] = $id_transaction;
			}
		}
	}
	
	// depouillement de la transaction
	list($id_transaction,$success) =  paybox_traite_reponse_transaction($config, $response);

	if ($response['abo'] AND $id_transaction) {

		// c'est un premier paiement d'abonnement, l'activer
		if ($response['ETAT_PBX']!=='PBX_RECONDUCTION_ABT'
			AND $success){
	
			// date de fin de mois de validite de la carte
			$date_fin = bank_date_fin_mois(2000+intval(substr($response['valid'],0,2)),substr($response['valid'],2,2));

			#spip_log('response:'.var_export($response,true),$mode.'db');
			#spip_log('date_fin:'.$date_fin,$mode.'db');

			// id_transaction contient toute la trame IDB_xx deriere le numero
			// on ne retient que la valeur entiere
			$id_transaction = intval($id_transaction);

			if ($activer_abonnement = charger_fonction('activer_abonnement','abos',true)){
				$activer_abonnement($id_transaction,$response['abo'],$mode,$date_fin);
			}
		}

		// c'est un renouvellement reussi, il faut repercuter sur l'abonnement
		if ($response['ETAT_PBX']==='PBX_RECONDUCTION_ABT'
			AND $success){

			if ($renouveler_abonnement = charger_fonction('renouveler_abonnement','abos',true)){
				$renouveler_abonnement($id_transaction,$response['abo'],$mode);
			}
		}

		// c'est un renouvellement en echec, il faut le resilier
		if ($response['ETAT_PBX']==='PBX_RECONDUCTION_ABT'
			AND !$success){

			if ($resilier = charger_fonction('resilier','abos',true)){
				$options = array(
					'notify_bank' => false, // pas la peine : paybox a deja resilie l'abo vu paiement refuse
					'immediat' => true,
					'message' => "[bank] Transaction #$id_transaction refusee",
				);
				$resilier("uid:".$response['abo'],$options);
			}

		}

	}
	return array($id_transaction,$success);	
}
?>
