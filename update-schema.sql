-- Script SQL pour ajouter la colonne category aux tables existantes
-- Exécuter ce script dans phpMyAdmin ou via WP-CLI

-- Ajouter la colonne category si elle n'existe pas déjà
ALTER TABLE `wp_bihr_products` 
ADD COLUMN `category` VARCHAR(255) NULL AFTER `stock_description`;

-- Message de confirmation
SELECT 'Colonne category ajoutée avec succès !' AS message;
