# FoodPrep-Recipes / Freezer Inventory

Dev:
- cp .env.example .env
- docker compose up -d --build
- App: http://localhost:8081
- phpMyAdmin: http://localhost:8082

API Beispiele (per curl):

```
# Alle Rezepte abfragen
curl -s "http://localhost:8081/api/recipes"

# Neues Rezept anlegen
curl -s -X POST "http://localhost:8081/api/recipes" \
  -H 'Content-Type: application/json' \
  -d '{
    "name": "Kartoffelpuffer",
    "recipe_type": "MEAL",
    "ingredients_text": "500 g Kartoffeln, mehligkochend\n1 Ei\nSalz, Pfeffer",
    "prep_text": "Kartoffeln reiben, mit Ei und Gewürzen mischen, ausbacken.",
    "yield_portions": 4,
    "kcal_per_portion": 220,
    "is_veggie": 1,
    "tags_text": "schnell,kinder"
  }'
```
